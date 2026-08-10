<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shelves of the asset library: brand kits, company information, contracts,
 * the deck everybody keeps asking for a link to.
 *
 * # Why a tree, when Files is flat
 *
 * The Files browser is deliberately flat, because a message attachment is not
 * filed anywhere — it is *said*, and the useful question is "what was shared
 * recently". An asset is the opposite: it is put somewhere on purpose so that
 * somebody who was not in the room can find it a year later. That only works if
 * there is somewhere to put it, so this is a real hierarchy that people
 * maintain.
 *
 * `parent_id` is nullable, and null means the top of the library rather than a
 * hidden root row. A synthetic root per workspace would be a row every query has
 * to know about and every fresh workspace has to have created for it; a null
 * reads the same and costs nothing.
 *
 * # Why two unique indexes rather than one
 *
 * A folder's name has to be unique among its siblings, and "siblings" includes
 * the top level. Postgres treats NULLs as distinct in a unique index, so a
 * single `(workspace_id, parent_id, name)` constraint would happily allow two
 * top-level folders both called Brand. The pair below splits the two cases so
 * the null side is covered explicitly.
 *
 * Both are on `lower(name)`: "Brand" and "brand" are the same shelf to everyone
 * except the database, and a library with both in it is one nobody trusts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Deleting a shelf deletes what is on it, all the way down. The
             * cascade is the database's, so a folder can never be left pointing
             * at a parent that is gone — which would strand its whole subtree
             * outside the tree the library is browsed by, visible to nothing.
             */
            $table->foreignId('parent_id')->nullable()
                ->constrained('asset_folders')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // Nullable so removing the person who made the folder does not take
            // the folder — and everything in it — with them.
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // Listing one shelf's children, which is every navigation step in
            // the library.
            $table->index(['workspace_id', 'parent_id', 'name']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX asset_folders_root_name_unique
            ON asset_folders (workspace_id, lower(name))
            WHERE parent_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX asset_folders_child_name_unique
            ON asset_folders (workspace_id, parent_id, lower(name))
            WHERE parent_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_folders');
    }
};
