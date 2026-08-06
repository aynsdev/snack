<?php

use App\Support\Positions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shelf projects sit on — "Platform", "Client work", "Internal".
 *
 * # Why a table rather than a column on projects
 *
 * A free-text "group" string on each project would let the same shelf be spelled
 * three ways, and renaming it would mean rewriting every project that used it. A
 * row means the name has one definition, renaming is one write, and the order
 * the groups appear in is something a workspace decides rather than something
 * the alphabet decides for it.
 *
 * # Ordering
 *
 * `position` is sparse — multiples of {@see Positions::GAP}, as
 * board columns and cards are. Moving a group swaps its position with its
 * neighbour's, so a move writes two rows rather than renumbering the list, and
 * two people rearranging different parts of it do not overwrite each other. The
 * gaps also leave room for a dropped-between insert if the list ever grows long
 * enough to be worth dragging.
 *
 * # Deleting a group
 *
 * `project_group_id` is nulled rather than cascaded: removing a shelf puts the
 * books back on the floor, it does not burn them. Nothing about a project
 * depends on being in a group, so ungrouped is an ordinary state — every
 * workspace starts out entirely ungrouped and many will stay that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 60);
            $table->integer('position');

            $table->timestampsTz();
        });

        // citext to match channel slugs and project keys: "Platform" and
        // "platform" are one shelf, and the constraint is what says so.
        DB::statement('ALTER TABLE project_groups ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE project_groups ADD CONSTRAINT project_groups_workspace_name_unique UNIQUE (workspace_id, name)');

        // The only way this table is ever read: a whole workspace's groups, in
        // the order they are drawn.
        DB::statement('CREATE INDEX project_groups_workspace_position_index ON project_groups (workspace_id, position)');

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('project_group_id')
                ->nullable()
                ->after('created_by')
                ->constrained('project_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_group_id');
        });

        Schema::dropIfExists('project_groups');
    }
};
