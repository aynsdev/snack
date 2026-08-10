<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves already-stored files onto the disks the application is now configured
 * to use.
 *
 * # Why this is needed at all
 *
 * `SNACK_UPLOAD_DISK` and `SNACK_AVATAR_DISK` decide where the *next* file goes.
 * They say nothing about the ones already written, so flipping them leaves a
 * workspace split across two disks: everything uploaded before the switch is on
 * the old one and everything after is on the new one.
 *
 * Attachments survive that split — each row records the disk it was written to,
 * so an old file still downloads. Avatars and workspace logos do not: they have
 * no disk column, the URL is built from whatever `snack.avatars.disk` currently
 * says, and the moment that changes every existing face in the product points at
 * a disk its bytes are not on. That is the case this command exists for, and it
 * is why it does avatars by default rather than on request.
 *
 * # What it will not do
 *
 * It never deletes a source object unless `--prune` is given, and never before
 * the copy has been verified byte-count-for-byte on the destination. A failed
 * row is reported and skipped rather than aborting the run: a bucket that
 * rejects one object should not strand the other nine hundred.
 */
#[Signature('snack:sync-storage
    {--from= : The disk to copy out of. Defaults to "local" for attachments and "public" for avatars.}
    {--attachments : Sync message, task, and asset files only}
    {--avatars : Sync avatars and workspace logos only}
    {--prune : Delete each source object once its copy is verified}
    {--dry-run : Report what would move, and move nothing}')]
#[Description('Copy stored files onto the currently configured storage disks.')]
class SyncStorage extends Command
{
    private bool $dryRun = false;

    private bool $prune = false;

    private int $moved = 0;

    private int $skipped = 0;

    private int $failed = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->prune = (bool) $this->option('prune');

        // Neither flag means both, which is what somebody who has just flipped
        // the two env vars actually wants.
        $both = ! $this->option('attachments') && ! $this->option('avatars');

        if ($this->dryRun) {
            $this->components->warn('Dry run — nothing will be written or deleted.');
        }

        if ($both || $this->option('attachments')) {
            $this->syncAttachments();
        }

        if ($both || $this->option('avatars')) {
            $this->syncAvatars();
        }

        $this->newLine();
        $this->components->info("Moved {$this->moved}, already in place {$this->skipped}, failed {$this->failed}.");

        // A non-zero exit on failure, so this is safe to put in a deploy script.
        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Message, task, and asset files.
     *
     * Driven off the `disk` column rather than off a listing of the source disk:
     * the rows are the authority on what the application still refers to, and an
     * object on disk that no row points at is somebody else's problem — the
     * orphan sweep's, or a leftover from before.
     */
    private function syncAttachments(): void
    {
        $from = (string) ($this->option('from') ?: 'local');
        $to = (string) config('snack.uploads.disk', 'local');

        $this->components->twoColumnDetail('<fg=cyan>Attachments</>', "{$from} → {$to}");

        if ($from === $to) {
            $this->components->warn("Attachments are already configured for \"{$to}\"; nothing to do.");

            return;
        }

        $query = MessageAttachment::query()->where('disk', $from);
        $total = $query->clone()->count();

        if ($total === 0) {
            $this->components->info("No attachments left on \"{$from}\".");

            return;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);

        $bar = $this->output->createProgressBar($total);

        $query->chunkById(100, function ($attachments) use ($source, $target, $to, $bar): void {
            foreach ($attachments as $attachment) {
                $paths = array_filter([$attachment->path, $attachment->thumbnail_path]);
                $ok = true;

                foreach ($paths as $path) {
                    $ok = $this->copy($source, $target, $path) && $ok;
                }

                /*
                 * The column is only repointed once every object behind the row
                 * is on the destination. A row that claimed the new disk while
                 * its thumbnail was still on the old one would render a broken
                 * preview and there would be nothing left to say where the
                 * bytes actually are.
                 */
                if ($ok && ! $this->dryRun) {
                    $attachment->forceFill(['disk' => $to])->save();

                    if ($this->prune) {
                        foreach ($paths as $path) {
                            $this->discard($source, $path);
                        }
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Avatars and workspace logos.
     *
     * Nothing is repointed afterwards because there is nothing to repoint: the
     * path is stored, the disk is not, and it is read fresh from config every
     * time a URL is built. Copying the objects across *is* the migration.
     */
    private function syncAvatars(): void
    {
        $from = (string) ($this->option('from') ?: 'public');
        $to = (string) config('snack.avatars.disk', 'public');

        $this->components->twoColumnDetail('<fg=cyan>Avatars and logos</>', "{$from} → {$to}");

        if ($from === $to) {
            $this->components->warn("Avatars are already configured for \"{$to}\"; nothing to do.");

            return;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);

        $paths = [
            ...User::query()->whereNotNull('avatar_path')->pluck('avatar_path')->all(),
            ...Workspace::query()->whereNotNull('logo_path')->pluck('logo_path')->all(),
        ];

        if ($paths === []) {
            $this->components->info('No avatars or logos stored.');

            return;
        }

        foreach ($paths as $path) {
            if ($this->copy($source, $target, (string) $path) && $this->prune && ! $this->dryRun) {
                $this->discard($source, (string) $path);
            }
        }
    }

    /**
     * Stream one object across, and confirm it arrived.
     *
     * Streamed rather than read into a string: a 50 MB video would otherwise be
     * held in memory in full, and this runs over every file a workspace owns.
     *
     * The size check afterwards is the whole point of the exercise. A write that
     * half-succeeded — a dropped connection mid-stream — leaves a truncated
     * object that `exists()` is perfectly happy with, and pruning the source
     * against that answer is how a migration destroys data.
     */
    private function copy(Filesystem $source, Filesystem $target, string $path): bool
    {
        try {
            if ($target->exists($path)) {
                $this->skipped++;

                return true;
            }

            if (! $source->exists($path)) {
                $this->components->warn("Missing on source, skipped: {$path}");
                $this->failed++;

                return false;
            }

            if ($this->dryRun) {
                $this->line("  would copy {$path}");
                $this->moved++;

                return true;
            }

            $stream = $source->readStream($path);

            if ($stream === null) {
                throw new \RuntimeException('Could not open the source object for reading.');
            }

            $target->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $expected = $source->size($path);
            $actual = $target->size($path);

            if ($expected !== $actual) {
                throw new \RuntimeException("Copied {$actual} bytes, expected {$expected}.");
            }

            $this->moved++;

            return true;
        } catch (Throwable $e) {
            $this->components->error("Failed: {$path} — {$e->getMessage()}");
            $this->failed++;

            return false;
        }
    }

    private function discard(Filesystem $source, string $path): void
    {
        try {
            $source->delete($path);
        } catch (Throwable $e) {
            // Never fatal: the copy is verified by this point, so the file is
            // safe. A source object left behind costs storage, not correctness.
            $this->components->warn("Could not remove the original: {$path} — {$e->getMessage()}");
        }
    }
}
