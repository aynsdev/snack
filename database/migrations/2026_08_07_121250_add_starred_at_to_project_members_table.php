<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this person keeps this project at the top of their sidebar.
 *
 * On the membership rather than in a table of its own, because a star is a
 * property of *your* relationship with a project and the sidebar already reads
 * that row: `project_members` is loaded for the viewer on every page in the
 * workspace, so starring costs no extra query anywhere. A separate
 * `project_stars` table would be a second join for one boolean.
 *
 * A timestamp rather than a flag, for the reason sprints and releases store
 * timestamps: "starred" is exactly "this has a date", and the date is what
 * orders the list when somebody stars a handful in a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->timestampTz('starred_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropColumn('starred_at');
        });
    }
};
