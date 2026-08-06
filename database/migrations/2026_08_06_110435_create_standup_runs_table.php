<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One occurrence of a standup: today's asking of it.
 *
 * # Why a row per occurrence rather than a computed date
 *
 * The run is what makes the scheduler safe to run every minute. Asking is not
 * idempotent — it direct-messages every member — so "have we already asked
 * today?" has to be a fact in the database rather than an inference from the
 * clock. The unique index on `(standup_id, scheduled_for)` is what actually
 * enforces it: two workers waking on the same minute both try to insert, and
 * exactly one succeeds. Checking first and then inserting would be a race that
 * DMs the whole team twice.
 *
 * # scheduled_for is a date in the standup's timezone
 *
 * Not a timestamp, and deliberately not UTC. It answers "which day's standup is
 * this?", which is the question the summary is titled with and the one a member
 * means when they say they already did today's. Storing the instant instead
 * would make the 09:30 run and its 13:30 close look like different days for a
 * team whose ask time sits either side of midnight UTC.
 *
 * `asked_at`, `reminded_at` and `closed_at` are the instants, and each is
 * nullable because each is a step that may not have happened yet. They are
 * stamps rather than a status column for the same reason a read pointer is:
 * "when" answers "whether", and the reverse is not true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standup_id')->constrained()->cascadeOnDelete();

            $table->date('scheduled_for');

            $table->string('state', 20);

            $table->timestampTz('asked_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            /*
             * The summary the bot posted in the channel. Nullable because a run
             * that nobody answered posts nothing, and `nullOnDelete` because an
             * admin deleting the summary message must not delete the answers it
             * was rendered from.
             */
            $table->foreignId('summary_message_id')->nullable()
                ->constrained('messages')->nullOnDelete();

            $table->timestampsTz();
        });

        // The guard that makes "ask once per day" true rather than hoped for.
        DB::statement(<<<'SQL'
            ALTER TABLE standup_runs
            ADD CONSTRAINT standup_runs_standup_date_unique UNIQUE (standup_id, scheduled_for)
        SQL);

        /*
         * The sweeper's query: the open runs, which is a handful at any moment
         * and a rounding error against every run ever recorded.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX standup_runs_open_index
            ON standup_runs (standup_id, scheduled_for)
            WHERE closed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('standup_runs');
    }
};
