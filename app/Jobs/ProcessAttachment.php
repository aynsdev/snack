<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Derives an attachment's thumbnail and dimensions after it lands.
 *
 * Runs off the request because the request it would otherwise run in is a
 * progress bar: decoding a 20-megapixel photo takes long enough to stall the
 * composer, and the composer is what the author is still typing into.
 *
 * # Why the attachment arrives as an id
 *
 * `SerializesModels` would re-load the model on the way in anyway, but it would
 * also *fail* the job if the row is gone. An upload can legitimately be deleted
 * between dispatch and execution — the author changed their mind while the
 * queue was busy — and that is a no-op, not an error, so the row is looked up
 * here and a miss returns quietly.
 *
 * A failure to produce a thumbnail is likewise not a failure of the job. The
 * client falls back to a file chip, which is exactly what a non-image gets.
 */
class ProcessAttachment implements ShouldQueue
{
    use Queueable;

    /**
     * Retried a couple of times for a transient disk hiccup, then left alone:
     * a file GD cannot decode will not become decodable on the fourth attempt.
     */
    public int $tries = 3;

    public function __construct(private readonly int $attachmentId) {}

    public function handle(ImageProcessor $images): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment === null || ! $attachment->isImage()) {
            return;
        }

        $disk = Storage::disk($attachment->disk);
        $contents = $disk->get($attachment->path);

        if ($contents === null) {
            return;
        }

        $metadata = $attachment->metadata;
        $dimensions = $images->dimensions($contents);

        if ($dimensions !== null) {
            $metadata = [...$metadata, ...$dimensions];
        }

        /*
         * Recorded so the client knows to serve the original rather than the
         * thumbnail. GD decodes only the first frame, so the thumbnail below is
         * a still — fine as a poster in the Files grid, wrong in a timeline
         * where the whole point of the file is that it moves.
         */
        $metadata['animated'] = $images->isAnimated($contents);

        $thumbnail = $images->canProcess($attachment->mime_type)
            ? $images->fit($contents, (int) config('snack.uploads.thumbnail_max_edge', 640))
            : null;

        $thumbnailPath = null;

        if ($thumbnail !== null) {
            /*
             * Written beside the original with a suffix rather than into a
             * parallel tree, so the two live and die together — the delete path
             * removes both by looking only at this row.
             */
            $thumbnailPath = preg_replace('/\.[^.\/]*$/', '', $attachment->path)
                .'_thumb.'.$thumbnail->extension;

            $disk->put($thumbnailPath, $thumbnail->contents);

            $metadata['thumbnail_width'] = $thumbnail->width;
            $metadata['thumbnail_height'] = $thumbnail->height;
        }

        $attachment->forceFill([
            'thumbnail_path' => $thumbnailPath,
            'metadata' => $metadata,
        ])->save();

        /*
         * The filename is part of the parent message's search vector, so a
         * message whose file finished processing after it was sent has to be
         * re-indexed. Cheap, and it keeps "find the message with the invoice in
         * it" working regardless of which finished first.
         */
        if ($attachment->message_id !== null) {
            IndexMessage::dispatch($attachment->message_id);
        }
    }
}
