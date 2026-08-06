<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring set of questions, asked of a channel's members.
 *
 * # Why the channel is the participant list
 *
 * A standup has a roster, and the roster is "whoever is on the team". Storing a
 * second list of people beside the channel's own would mean two answers to that
 * question and a daily opportunity for them to disagree — somebody joins
 * #mobile and is still never asked, or leaves and is asked for another month.
 * The channel is already the thing people join and leave when the team changes,
 * so it *is* the roster, and the summary is posted where that roster reads.
 *
 * # Why the schedule is a day set plus a local time
 *
 * "Weekdays at 09:30" is what a team actually agrees on, and it is three
 * columns: which days, what time, and whose clock. The timezone is the
 * standup's own rather than each member's, because a standup is a shared
 * ceremony — a team that meets at 09:30 means one moment, not one moment per
 * person. The daily digest is the opposite case and stores no timezone at all
 * for that reason: it is a private summary, so it follows its reader's clock.
 *
 * `ask_at` is a bare TIME, not a timestamp: it recurs, so a date on it would be
 * wrong every day but one, and the offset it should be read at changes twice a
 * year with daylight saving.
 *
 * # The two windows
 *
 * `reminder_after_minutes` is the nudge for people who have not answered, and is
 * nullable because a team that does not want to be chased should not have to
 * pick a number to say so. `closes_after_minutes` is when the summary is posted
 * regardless — without it one person on holiday holds the standup open forever
 * and the channel gets nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Cascades: a standup posts its summary into this channel and asks
             * the people in it. Neither question has an answer once the channel
             * is gone, and a standup pointing at nothing would go on DMing a
             * roster that no longer exists.
             */
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');

            /*
             * The questions, in the order they are asked, as a jsonb array of
             * strings.
             *
             * A table of question rows would buy per-question ids and explicit
             * ordering, and cost the thing that actually matters: a past run has
             * to stay readable after the questions are reworded. Answers
             * snapshot the question they were given — see
             * standup_responses.answers — so what lives here is only ever the
             * *current* wording. There is no history to model and nothing to
             * join to.
             */
            $table->jsonb('questions');

            /*
             * Which days it runs, as ISO-8601 weekday numbers (1 = Monday, 7 =
             * Sunday) in a jsonb array. ISO rather than PHP's `w`, because it is
             * what Carbon's `dayOfWeekIso` returns and what the UI orders by: a
             * week that starts on Monday is the one a working week has.
             */
            $table->jsonb('days_of_week');

            $table->time('ask_at');
            $table->string('timezone', 64);

            $table->unsignedSmallInteger('reminder_after_minutes')->nullable();
            $table->unsignedSmallInteger('closes_after_minutes')->default(240);

            /*
             * Paused rather than deleted. A team that skips standups for a
             * sprint wants its questions back afterwards, and deleting would
             * take every past run's answers with it.
             */
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });

        /*
         * The scheduler's own query, which runs every minute: "which live
         * standups might be due?". Partial on `is_active`, so an installation
         * whose standups are all paused costs nothing to sweep.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX standups_active_index
            ON standups (workspace_id, id)
            WHERE is_active = true
        SQL);

        DB::statement('CREATE INDEX standups_channel_index ON standups (channel_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('standups');
    }
};
