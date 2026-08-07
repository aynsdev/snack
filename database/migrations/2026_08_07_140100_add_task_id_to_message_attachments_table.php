<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A file can now hang off a card instead of a message.
 *
 * # Why not a second table
 *
 * Everything an attachment *is* — the private-disk key, the detected mime type,
 * the queued thumbnail, the signed download route that re-authorizes on every
 * hit — is identical for both. A `task_attachments` table would be that whole
 * apparatus written twice, and the second copy is the one that would quietly
 * miss the next security fix. What differs is a single question, "who may read
 * this", and that is one branch in the download controller.
 *
 * # The two rules this adds
 *
 * A row hangs off *at most one* thing: the check constraint below is what stops
 * a file from being simultaneously in a channel and on a card, which would make
 * "who may read this" ambiguous — precisely the question the download route
 * must never have two answers to.
 *
 * And the orphan sweep now means "claimed by nothing". `snack:prune-uploads`
 * reads the partial index laid down here; without rebuilding it to include
 * `task_id`, every file attached to a card would look abandoned and be deleted
 * within the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('message_id')
                ->constrained()->cascadeOnDelete();

            $table->index(['task_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_one_owner_check
            CHECK (message_id IS NULL OR task_id IS NULL)
        SQL);

        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');

        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL AND task_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE message_attachments DROP CONSTRAINT IF EXISTS message_attachments_one_owner_check');
        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL
        SQL);
    }
};
