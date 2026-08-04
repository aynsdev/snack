<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in a huddle, and who was.
 *
 * A row per visit rather than per person: leaving and rejoining is completely
 * ordinary — someone switches from laptop to phone, or drops off the wifi — and
 * collapsing that into one row would make "when did Ada join?" unanswerable and
 * lose the fact that she was here for the first half.
 *
 * `left_at` is the only thing separating the roster from the history, which is
 * why the partial unique index below is on the live rows alone: one person may
 * have five finished visits to a huddle and at most one unfinished one. That is
 * also what makes joining idempotent — a double-clicked Join button, or a
 * client that reconnects and re-announces, hits the index rather than quietly
 * putting somebody in the roster twice.
 *
 * The roster here is Snack's record, not LiveKit's. The media server keeps its
 * own and is authoritative for who can currently be *heard*; this is what the
 * rest of the application reads, and what someone who has not joined the room
 * is shown. They agree in the ordinary case and this one is allowed to lag,
 * which is why the sweep in App\Console\Commands\SweepHuddles exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huddle_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('huddle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();

            $table->timestampsTz();

            // The roster query: everyone in this huddle, live rows first.
            $table->index(['huddle_id', 'left_at']);

            // "Am I already in a huddle somewhere?" — what stops the client
            // opening a second one while the first is still connected.
            $table->index(['user_id', 'left_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE huddle_participants
            ADD CONSTRAINT huddle_participants_ends_after_join_check
            CHECK (left_at IS NULL OR left_at >= joined_at)
        SQL);

        // At most one live visit per person per huddle. See the class docblock:
        // this is what makes joining idempotent under a double click.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX huddle_participants_one_live_per_user
            ON huddle_participants (huddle_id, user_id)
            WHERE left_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('huddle_participants');
    }
};
