<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A file can now be the bytes behind a library asset, as well as behind a
 * message or a card.
 *
 * # Why the pointer is on this side
 *
 * `assets` could just as easily have carried an `attachment_id`, and that was
 * the first shape. It is wrong for two reasons that only show up later:
 *
 * 1. The orphan sweep. `snack:prune-uploads` deletes any attachment claimed by
 *    nothing, reading the partial index below. An asset whose ownership was
 *    recorded on the *other* table would look abandoned to it, and every logo in
 *    the library would be deleted within the day. Putting the pointer here means
 *    the sweep excludes assets by construction rather than by somebody
 *    remembering to add a join to it.
 *
 * 2. The other two places that ask "is this file spoken for" — the composer's
 *    tray delete, and the claim that runs when a message is sent — ask it of
 *    this row. Both of them would have happily taken an asset's file out from
 *    under the library.
 *
 * So ownership stays one column on one table, the check constraint keeps saying
 * a file belongs to exactly one thing, and the three existing guards keep
 * working with no changes to what they mean.
 *
 * # At most one file per asset
 *
 * Enforced by the partial unique index rather than by the action that writes it.
 * An asset with two files behind it has no answer to "download this", and the
 * question of which row the library shows would be settled by whichever came
 * back first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('task_id')
                ->constrained('assets')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX message_attachments_asset_unique ON message_attachments (asset_id) WHERE asset_id IS NOT NULL');

        /*
         * One owner, now counted rather than compared. The two-column version
         * this replaces was a plain OR; with three columns that spelling turns
         * into a truth table nobody reads correctly, and the next owner added
         * would have to get it right again.
         */
        DB::statement('ALTER TABLE message_attachments DROP CONSTRAINT IF EXISTS message_attachments_one_owner_check');

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

        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');

        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL AND task_id IS NULL AND asset_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE message_attachments DROP CONSTRAINT IF EXISTS message_attachments_one_owner_check');
        DB::statement('DROP INDEX IF EXISTS message_attachments_orphaned_index');
        DB::statement('DROP INDEX IF EXISTS message_attachments_asset_unique');

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE message_attachments
            ADD CONSTRAINT message_attachments_one_owner_check
            CHECK (message_id IS NULL OR task_id IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL AND task_id IS NULL
        SQL);
    }
};
