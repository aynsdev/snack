<?php

use App\Enums\MessageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One unified message table for channels, conversations, and thread replies.
 *
 * Design notes:
 *  - A message belongs to exactly one of channel/conversation, enforced by a
 *    CHECK rather than trusted to the application.
 *  - `body` holds sanitized rich text; `body_plaintext` is the flattened form
 *    used for search snippets, notification previews, and sidebar previews.
 *  - `search_vector` is a real tsvector column (not a generated one) because it
 *    also folds in the sender name, channel name, and attachment filenames,
 *    which live in other tables. It is refreshed by a queued job in Phase 5.
 *  - `reply_count` / `last_reply_at` are denormalized so the thread affordance
 *    on a parent message never triggers a per-message COUNT.
 *  - Pagination is cursor-based on the monotonically increasing `id`, so the
 *    composite indexes below lead with the parent key and descend on id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_message_id')->nullable()->constrained('messages')->cascadeOnDelete();

            $table->text('body');
            $table->text('body_plaintext');

            $table->string('type', 20)->default(MessageType::Text->value);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->unsignedInteger('reply_count')->default(0);
            $table->timestampTz('last_reply_at')->nullable();
            $table->boolean('has_attachments')->default(false);

            $table->timestampTz('edited_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_type_check
            CHECK (type IN ('text', 'file', 'system', 'announcement'))
        SQL);

        // Exactly one container. `num_nonnulls` is clearer than an XOR of IS NULL.
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_single_container_check
            CHECK (num_nonnulls(channel_id, conversation_id) = 1)
        SQL);

        // A reply may not be its own parent.
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_parent_is_not_self_check
            CHECK (parent_message_id IS NULL OR parent_message_id <> id)
        SQL);

        // --- Cursor pagination -------------------------------------------------
        // Root-level channel history, newest first, excluding deleted rows and
        // thread replies (which are fetched through the thread panel instead).
        DB::statement(<<<'SQL'
            CREATE INDEX messages_channel_timeline_index
            ON messages (channel_id, id DESC)
            WHERE deleted_at IS NULL AND parent_message_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX messages_conversation_timeline_index
            ON messages (conversation_id, id DESC)
            WHERE deleted_at IS NULL AND parent_message_id IS NULL
        SQL);

        // Thread replies in posting order.
        DB::statement(<<<'SQL'
            CREATE INDEX messages_thread_index
            ON messages (parent_message_id, id)
            WHERE parent_message_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // --- Search and filters ------------------------------------------------
        DB::statement('ALTER TABLE messages ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX messages_search_vector_index ON messages USING gin (search_vector)');

        // "from:@user" filter, and per-sender moderation views.
        DB::statement('CREATE INDEX messages_workspace_sender_index ON messages (workspace_id, sender_id, id DESC)');

        // "before:"/"after:" date filters and retention sweeps.
        DB::statement('CREATE INDEX messages_workspace_created_index ON messages (workspace_id, created_at DESC)');

        DB::statement('CREATE INDEX messages_metadata_index ON messages USING gin (metadata jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
