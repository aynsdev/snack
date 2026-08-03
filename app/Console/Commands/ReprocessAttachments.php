<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-derives image metadata for attachments that already landed.
 *
 * {@see ProcessAttachment} runs once, when a file is uploaded, so a change to
 * what it records reaches only the next upload. Two such changes have already
 * needed backfilling:
 *
 * - `width`/`height` now describe the size an image *displays* at rather than
 *   its raw buffer, so a photo carrying an EXIF quarter-turn stops being poured
 *   into a box of the wrong shape.
 * - `animated` now records whether the file moves, which is what lets the
 *   timeline serve a GIF's original instead of a frozen thumbnail.
 *
 * Neither is visible in the database as "missing" — an old row has plausible
 * dimensions and simply no `animated` key — so the default pass targets rows
 * that have never been asked the animation question. `--all` re-reads
 * everything, which is what a change to the orientation handling wants.
 *
 * Re-queued rather than done inline: the work is decoding every image the
 * workspace has ever received, and that belongs on a worker.
 */
#[Signature('snack:reprocess-attachments {--all : Re-read every image, not just the ones never examined} {--chunk=200 : Rows per batch}')]
#[Description('Rebuild thumbnails, dimensions, and animation flags for uploaded images.')]
class ReprocessAttachments extends Command
{
    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $all = (bool) $this->option('all');

        $query = MessageAttachment::query()->where('mime_type', 'like', 'image/%');

        if (! $all) {
            /*
             * Rows written before the flag existed. `?` is the Postgres JSONB
             * key-exists operator, so this is an indexable test for "never
             * examined" rather than a comparison against a value the column
             * legitimately holds as false.
             */
            $query->whereRaw("NOT (metadata ?? 'animated')");
        }

        $this->components->info($all
            ? 'Re-reading every uploaded image…'
            : 'Backfilling images that were never examined…');

        $queued = 0;

        $query->orderBy('id')->chunkById($chunk, function ($attachments) use (&$queued): void {
            foreach ($attachments as $attachment) {
                ProcessAttachment::dispatch($attachment->id);
                $queued++;
            }
        });

        $this->components->info("Queued {$queued} image(s) for reprocessing.");

        return self::SUCCESS;
    }
}
