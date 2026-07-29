<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized mentions.
 *
 * Extracting mentions at write time means the unread-mention badge is a single
 * partial-index scan instead of a full-text query over message bodies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('mention_type', 20);
            $table->timestampTz('read_at')->nullable();

            $table->timestampsTz();

            // @everyone expands to one row per member, so the same message can
            // mention the same person only once per type.
            $table->unique(['message_id', 'mentioned_user_id', 'mention_type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mentions
            ADD CONSTRAINT mentions_type_check
            CHECK (mention_type IN ('user', 'channel', 'everyone', 'here'))
        SQL);

        // The mention badge: unread mentions for one user in one workspace.
        DB::statement(<<<'SQL'
            CREATE INDEX mentions_unread_index
            ON mentions (mentioned_user_id, workspace_id, id DESC)
            WHERE read_at IS NULL
        SQL);

        // The "Mentions" view, read or not.
        DB::statement('CREATE INDEX mentions_user_recent_index ON mentions (mentioned_user_id, workspace_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
