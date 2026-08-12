<?php

namespace App\Ai;

use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The files Bite decided to put in its reply.
 *
 * # Why a collector rather than a return value
 *
 * A tool may only return a string, so `attach_file` cannot hand an attachment
 * back to the message that is being composed. It records the intent here
 * instead, and {@see App\Jobs\AnswerWithBite} drains this after the run and
 * passes what it finds to `SendMessage` as ordinary attachments. Mutable and
 * request-scoped on purpose: it exists for exactly one answer.
 *
 * # Why a copy rather than the original row
 *
 * {@see App\Actions\Attachments\LinkAttachments} claims uploads with
 * `WHERE message_id IS NULL`, which is what stops two messages fighting over
 * one upload. A library asset or an already-shared file has that column set, so
 * "attaching" it to Bite's reply by claiming the existing row would not attach
 * it — it would *move* it, silently emptying the message or the library shelf
 * it came from.
 *
 * So the bytes are copied to a new key and a new unclaimed row is written over
 * them. On object storage that is a server-side copy, so it costs a request
 * rather than a download and an upload. The copy deliberately carries no
 * `asset_id`: it is a file in a conversation now, not a second entry on the
 * shelf, and pointing it at the asset would give the library two rows claiming
 * to be the same thing.
 */
class BiteAttachments
{
    /** @var Collection<int, MessageAttachment> */
    private Collection $pending;

    public function __construct()
    {
        $this->pending = new Collection;
    }

    /**
     * Copy a file the asker may read into a fresh upload for Bite's reply.
     *
     * Returns null when the bytes could not be copied, which the tool reports
     * rather than throwing: a failed attachment should cost the file, not the
     * whole answer.
     */
    public function add(MessageAttachment $source, User $bot): ?MessageAttachment
    {
        if ($this->pending->contains(fn (MessageAttachment $held): bool => ($held->metadata['copied_from'] ?? null) === $source->id)) {
            // Asked for twice in one answer. The first copy is already going
            // out; a second would deliver the file twice.
            return $this->pending->first(
                fn (MessageAttachment $held): bool => ($held->metadata['copied_from'] ?? null) === $source->id,
            );
        }

        $disk = (string) config('snack.uploads.disk', 'local');

        $path = sprintf(
            'workspaces/%d/attachments/%s/%s',
            $source->workspace_id,
            now()->format('Y/m'),
            Str::ulid()->toBase32().$this->extension($source),
        );

        try {
            $stream = Storage::disk($source->disk)->readStream($source->path);

            if ($stream === null) {
                return null;
            }

            Storage::disk($disk)->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $copy = MessageAttachment::create([
            'workspace_id' => $source->workspace_id,
            'message_id' => null,
            'uploaded_by' => $bot->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $source->original_name,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            // Recorded so the copy is traceable back to what it came from, and
            // so a second request for the same file in one answer is a no-op.
            'metadata' => ['copied_from' => $source->id],
        ]);

        $this->pending->push($copy);

        return $copy;
    }

    /**
     * Everything to attach, and forget it.
     *
     * Drained rather than read so that a retried job cannot attach the same
     * copies twice.
     *
     * @return Collection<int, MessageAttachment>
     */
    public function drain(): Collection
    {
        $pending = $this->pending;

        $this->pending = new Collection;

        return $pending;
    }

    /**
     * The extension from the *stored* key, never from the name the uploader
     * typed — the same rule {@see App\Actions\Attachments\StoreUpload} follows,
     * for the same reason: nothing a client supplied is used to build a path.
     */
    private function extension(MessageAttachment $source): string
    {
        $extension = pathinfo($source->path, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.'.$extension;
    }
}
