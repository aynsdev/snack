<?php

use App\Actions\Projects\RemoveProjectMember;
use App\Enums\ProjectRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on a project.
 *
 * This table is the answer to "who can be assigned this task?". Being in the
 * workspace is not enough: a board whose assignee picker lists all two hundred
 * colleagues is a board where the wrong person gets picked, and the brief is
 * explicit that people are assigned work on the projects they are *on*.
 *
 * Rows are removed when someone leaves, and their open tasks are unassigned by
 * {@see RemoveProjectMember} rather than by a database
 * cascade — an assignment pointing at a departed member is exactly the kind of
 * silent wrong answer the board must not give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default(ProjectRole::Member->value);
            $table->timestampTz('joined_at')->useCurrent();

            $table->timestampsTz();

            $table->unique(['project_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE project_members
            ADD CONSTRAINT project_members_role_check
            CHECK (role IN ('lead', 'member'))
        SQL);

        // "Which projects am I on?" — the sidebar's list, on every request.
        DB::statement('CREATE INDEX project_members_user_index ON project_members (user_id, project_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
