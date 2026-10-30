<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Constraints, indexes and bookkeeping for the agent dispatcher's outbox and
 * settings.
 *
 * - The two agent columns must differ, as UpdateProject checks under its lock.
 * - `agent_channel_id` is what every message write asks "is this an agent
 *   channel?" by; the two column settings are what a column deletion looks up.
 *   Partial, because nearly every project has none of them.
 * - The prune reads delivered rows by `delivered_at` and dead ones by
 *   `updated_at`; without these it scans the whole table nightly.
 * - Deleting a message scrubs its text from the events that carry it, found by
 *   the message id inside the payload.
 * - `dispatched_at` records when a delivery job was last queued for a row, so
 *   the sweeper re-queues only rows whose job looks lost — see
 *   App\Console\Commands\SweepOutboundEvents.
 *
 * Plain statements in the migration's transaction: `projects` is small, and
 * `outbound_events` is pruned to days of rows, so the brief exclusive locks
 * these take are not worth `CONCURRENTLY` and a non-transactional migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_agent_columns_distinct
            CHECK (agent_in_progress_column_id IS NULL OR agent_review_column_id IS NULL OR agent_in_progress_column_id <> agent_review_column_id)
        SQL);

        foreach (['agent_channel_id', 'agent_in_progress_column_id', 'agent_review_column_id'] as $column) {
            DB::statement("CREATE INDEX projects_{$column}_index ON projects ({$column}) WHERE {$column} IS NOT NULL");
        }

        Schema::table('outbound_events', function (Blueprint $table) {
            $table->timestampTz('dispatched_at')->nullable()->after('available_at');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX outbound_events_delivered_prune_index
            ON outbound_events (delivered_at)
            WHERE status = 'delivered'
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX outbound_events_dead_prune_index
            ON outbound_events (updated_at)
            WHERE status = 'dead'
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX outbound_events_message_index
            ON outbound_events (workspace_id, ((payload -> 'data' -> 'message' ->> 'id')))
            WHERE (payload -> 'data' -> 'message' ->> 'id') IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbound_events_message_index');
        DB::statement('DROP INDEX IF EXISTS outbound_events_dead_prune_index');
        DB::statement('DROP INDEX IF EXISTS outbound_events_delivered_prune_index');

        Schema::table('outbound_events', function (Blueprint $table) {
            $table->dropColumn('dispatched_at');
        });

        foreach (['agent_channel_id', 'agent_in_progress_column_id', 'agent_review_column_id'] as $column) {
            DB::statement("DROP INDEX IF EXISTS projects_{$column}_index");
        }

        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_agent_columns_distinct');
    }
};
