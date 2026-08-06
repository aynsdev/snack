<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One person's answers to one run — and, until they give them, the fact that
 * they were asked.
 *
 * # Why the row exists before there is an answer
 *
 * A pending row is what makes "who has not replied yet" a query rather than a
 * set difference between the channel's roster and the answers received. The
 * reminder pass reads it, the summary's "no response from" line reads it, and
 * both stay correct when somebody joins the channel halfway through the
 * morning: they were not asked, so they have no row, so they are not chased for
 * an answer to a question they never saw.
 *
 * # answers snapshots the questions
 *
 * `answers` is a jsonb array of `{question, answer}` pairs, carrying the
 * question text as it was asked. Storing only the answers and re-reading the
 * wording from the standup would silently rewrite history the first time an
 * admin edits a question: last week's replies would be shown under this week's
 * questions, which is worse than showing nothing, because it reads as true.
 *
 * # prompt_message_id
 *
 * The direct message the bot asked with. Kept so submitting can stamp that
 * message as answered — the prompt card renders from the message's own
 * metadata, so without this the button to answer would still be sitting there
 * tomorrow. `nullOnDelete`: deleting the DM must not delete the answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standup_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standup_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('state', 20);

            /*
             * The conversation the bot asked in, so a reminder lands in the same
             * thread as the question instead of opening a second one.
             */
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete();

            $table->foreignId('prompt_message_id')->nullable()
                ->constrained('messages')->nullOnDelete();

            $table->jsonb('answers')->default(DB::raw("'[]'::jsonb"));

            $table->timestampTz('submitted_at')->nullable();

            $table->timestampsTz();
        });

        /*
         * One row per person per run. Also the guard on the ask itself: a
         * retried ask cannot double-DM somebody, because the second insert of
         * their row fails.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE standup_responses
            ADD CONSTRAINT standup_responses_run_user_unique UNIQUE (standup_run_id, user_id)
        SQL);

        // "What is still outstanding for this person?" — the query behind both
        // the reminder pass and the prompt card's own state.
        DB::statement(<<<'SQL'
            CREATE INDEX standup_responses_pending_index
            ON standup_responses (user_id, standup_run_id)
            WHERE submitted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('standup_responses');
    }
};
