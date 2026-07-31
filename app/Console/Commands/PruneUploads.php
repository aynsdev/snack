<?php

namespace App\Console\Commands;

use App\Actions\Attachments\DeleteUpload;
use App\Models\MessageAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sweeps uploads that were never sent.
 *
 * A file is uploaded while the composer is still open, so every abandoned draft,
 * closed tab, and changed mind leaves bytes on the disk with a null `message_id`
 * and nothing in the application that will ever look at them again. Left alone,
 * that is unbounded growth in the one place growth costs money.
 *
 * The window is deliberately generous — somebody can legitimately leave a
 * composer open across a lunch break — and the partial index laid down in the
 * migration (`message_attachments_orphaned_index`) means the sweep reads only
 * unattached rows rather than scanning the table.
 */
#[Signature('snack:prune-uploads {--hours= : Override the age threshold}')]
#[Description('Delete uploaded files that were never attached to a sent message.')]
class PruneUploads extends Command
{
    public function handle(DeleteUpload $uploads): int
    {
        $hours = (int) ($this->option('hours') ?? config('snack.uploads.orphan_ttl_hours', 24));
        $cutoff = now()->subHours(max(1, $hours));

        $deleted = 0;

        MessageAttachment::query()
            ->orphaned()
            ->where('created_at', '<', $cutoff)
            /*
             * Chunked by id rather than by page: the loop deletes the rows it is
             * iterating, and an OFFSET-based chunk would skip half of them as the
             * result set shrank underneath it.
             */
            ->chunkById(200, function ($attachments) use ($uploads, &$deleted): void {
                foreach ($attachments as $attachment) {
                    $uploads->handle($attachment);
                    $deleted++;
                }
            });

        $this->components->info("Pruned {$deleted} unattached upload(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
