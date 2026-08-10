<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One thing on a shelf in the asset library.
 *
 * # Why this is not just an attachment with a folder on it
 *
 * The bytes *are* an attachment — `message_attachments` already carries the
 * private-disk key, the detected mime type, the queued thumbnail, and the signed
 * download route that re-authorizes on every hit, and that apparatus is not
 * going to be written a second time (see the migration that put `task_id` on
 * that table for the argument in full).
 *
 * What an asset adds is an *identity the file does not have*. A logo uploaded as
 * `Logo_FINAL_v3 (2).svg` is called "Primary logo — light backgrounds" in the
 * library, it has a note saying when to use it instead of the other one, and it
 * stays that thing when somebody replaces the file underneath it. None of that
 * belongs on a row whose job is to describe bytes, so it lives here and the
 * attachment points back with its `asset_id`.
 *
 * # `folder_id` is nullable
 *
 * Null is the top of the library, matching `asset_folders.parent_id`. Uploading
 * before deciding where something goes is the normal case, not an error state,
 * and a library that refuses the file until you have made a folder for it is one
 * people route around by sending the file in a channel instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Deleting a shelf deletes what is on it; the attachment row and its
            // bytes follow from there — see DeleteAssetFolder, which sweeps the
            // disk before letting the cascade take the rows.
            $table->foreignId('folder_id')->nullable()
                ->constrained('asset_folders')->cascadeOnDelete();

            $table->string('name', 200);
            $table->string('description', 2000)->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // Listing a shelf, newest first — the library's only hot query.
            $table->index(['workspace_id', 'folder_id', 'id']);

            // The library-wide "recently added" view, which ignores the tree.
            $table->index(['workspace_id', 'created_at']);
        });

        /*
         * Name search across the library. A contains-match rather than the
         * similarity operator, for the reason the file browser uses one: a
         * two-letter prefix should still find what the person obviously means,
         * and a GIN trigram index serves `ilike '%…%'` perfectly well.
         */
        DB::statement('CREATE INDEX assets_name_trigram_index ON assets USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
