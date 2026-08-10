<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A Canvas cover is a private attachment with the Canvas as its owner. */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('canvas_id')->nullable()->after('asset_id')
                ->constrained('canvases')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX message_attachments_canvas_unique ON message_attachments (canvas_id) WHERE canvas_id IS NOT NULL');
        DB::statement('ALTER TABLE message_attachments DROP CONSTRAINT IF EXISTS message_attachments_one_owner_check');
        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_one_owner_check
            CHECK (
                (message_id IS NOT NULL)::int
                + (task_id IS NOT NULL)::int
                + (asset_id IS NOT NULL)::int
                + (canvas_id IS NOT NULL)::int
                <= 1
            )
        SQL);
        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');
        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL AND task_id IS NULL AND asset_id IS NULL AND canvas_id IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE message_attachments DROP CONSTRAINT IF EXISTS message_attachments_one_owner_check');
        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');
        DB::statement('DROP INDEX IF EXISTS message_attachments_canvas_unique');

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canvas_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_one_owner_check
            CHECK (
                (message_id IS NOT NULL)::int
                + (task_id IS NOT NULL)::int
                + (asset_id IS NOT NULL)::int
                <= 1
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL AND task_id IS NULL AND asset_id IS NULL
        SQL);
    }
};
