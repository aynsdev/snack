<?php

use App\Support\Positions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A heading in one person's channel list — "Team", "Clients", "Noise".
 *
 * # Why this is per user and project groups are not
 *
 * A shelf of projects belongs to the workspace: everyone who opens the project
 * list sees the same projects arranged the same way, so the arrangement is a
 * property of the list itself. The channel list is not that list. It holds the
 * channels *you* joined, which is a different set for every person in the
 * workspace, so a shared arrangement would be headings over channels half the
 * workspace cannot see — and, for private channels, headings naming rooms they
 * are not allowed to know exist. Hence `user_id`: a group is one person's view
 * of their own sidebar, and nobody else ever reads it.
 *
 * # Why the assignment lives on channel_members
 *
 * The membership row is already the per-user record for a channel — it is where
 * the mute flag and the read pointer live — and it exists exactly when the
 * channel is in the sidebar. Putting `channel_group_id` there means filing a
 * channel is one write to a row that is already loaded, leaving a channel
 * unfiles it with no cleanup, and there is no way to express the nonsense state
 * of a channel filed by someone who is not in it.
 *
 * # Ordering and deletion
 *
 * `position` is sparse — multiples of {@see Positions::GAP} — and a move swaps
 * two rows rather than renumbering the list, matching project groups and board
 * columns. Deleting a group nulls the assignment rather than cascading: the
 * channels come back to the ungrouped list, which is where every channel starts
 * and where most of them stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // The owner. Cascaded rather than nulled: a group with no one to
            // read it is not a group, and leaving orphans would put nameless
            // headings in the unique index below.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->integer('position');

            $table->timestampsTz();
        });

        // citext to match project groups and channel slugs: "Team" and "team"
        // are one heading, and the constraint is what says so.
        DB::statement('ALTER TABLE channel_groups ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE channel_groups ADD CONSTRAINT channel_groups_workspace_user_name_unique UNIQUE (workspace_id, user_id, name)');

        // The only way this table is ever read: one person's groups in one
        // workspace, in the order they are drawn.
        DB::statement('CREATE INDEX channel_groups_workspace_user_position_index ON channel_groups (workspace_id, user_id, position)');

        Schema::table('channel_members', function (Blueprint $table) {
            $table->foreignId('channel_group_id')
                ->nullable()
                ->after('user_id')
                ->constrained('channel_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_group_id');
        });

        Schema::dropIfExists('channel_groups');
    }
};
