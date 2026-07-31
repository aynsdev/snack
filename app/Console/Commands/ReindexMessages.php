<?php

namespace App\Console\Commands;

use App\Services\MessageIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fills in `messages.search_vector`.
 *
 * Two jobs, one command. By default it backfills only rows with no vector —
 * every message written before Phase 5, plus anything whose indexing job was
 * lost — which is safe to run repeatedly and stops as soon as it runs out.
 *
 * `--all` rebuilds every row, which is what a change to the dictionary or the
 * weights in config/snack.php requires: those decide how text is *stemmed*, so
 * vectors built under the old setting will not match queries parsed under the
 * new one, and search quietly returns nothing rather than failing loudly.
 */
#[Signature('snack:reindex-messages {--all : Rebuild every vector, not just the missing ones} {--chunk=500 : Rows per statement}')]
#[Description('Build the full-text search vectors for messages.')]
class ReindexMessages extends Command
{
    public function handle(MessageIndexer $indexer): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $all = (bool) $this->option('all');

        $this->components->info($all
            ? 'Rebuilding every message search vector…'
            : 'Backfilling missing message search vectors…');

        $written = $indexer->backfill($chunk, $all);

        $this->components->info("Indexed {$written} message(s).");

        return self::SUCCESS;
    }
}
