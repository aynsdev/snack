<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A live audio/video session hanging off a channel or a conversation.
 *
 * Design notes:
 *  - Attached to exactly one container, enforced by the same `num_nonnulls`
 *    CHECK the messages table uses. Huddles are a second thing that happens
 *    inside a container, so they inherit the container's shape rather than
 *    inventing a parallel one.
 *  - `public_id` is a ULID and doubles as the LiveKit room name. The media
 *    server is reachable by anyone holding a token, so the room it names must
 *    be unguessable and must carry no meaning — a room called "channel-7" would
 *    invite enumeration against infrastructure Laravel does not sit in front of.
 *  - A huddle is *live* while `ended_at` is null, and the two partial unique
 *    indexes below are what make "one live huddle per container" true. Two
 *    people pressing Huddle at the same instant is the ordinary case, not an
 *    edge case, and an application-level find-or-create would race — the second
 *    one is meant to walk into the first one's huddle, not open a rival room
 *    that splits the channel in half.
 *  - Rows are kept after the call ends. They are the record that a huddle
 *    happened, which is what lets a channel say "huddle ended · 12 minutes"
 *    rather than having the whole thing vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huddles', function (Blueprint $table) {
            $table->id();

            // 26 characters: a ULID in Crockford base32.
            $table->char('public_id', 26)->unique();

            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();

            // "What happened in this workspace lately", and the sweep's scan for
            // huddles that have outlived everyone in them.
            $table->index(['workspace_id', 'ended_at']);
        });

        // Exactly one container, matching messages_single_container_check.
        DB::statement(<<<'SQL'
            ALTER TABLE huddles
            ADD CONSTRAINT huddles_single_container_check
            CHECK (num_nonnulls(channel_id, conversation_id) = 1)
        SQL);

        // A huddle cannot finish before it starts. Cheap to assert, and the one
        // thing that would make every duration this table reports nonsense.
        DB::statement(<<<'SQL'
            ALTER TABLE huddles
            ADD CONSTRAINT huddles_ends_after_start_check
            CHECK (ended_at IS NULL OR ended_at >= started_at)
        SQL);

        /*
         * One live huddle per container.
         *
         * Partial rather than plain: a channel may have had a hundred huddles
         * over its life, and only the unfinished one is exclusive. This is the
         * constraint App\Actions\Huddles\JoinHuddle races against on purpose —
         * it catches the unique violation and re-reads, rather than trying to
         * be first.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX huddles_one_live_per_channel
            ON huddles (channel_id)
            WHERE ended_at IS NULL AND channel_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX huddles_one_live_per_conversation
            ON huddles (conversation_id)
            WHERE ended_at IS NULL AND conversation_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('huddles');
    }
};
