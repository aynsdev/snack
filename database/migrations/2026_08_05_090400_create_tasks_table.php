<?php

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One card.
 *
 * # Where a task is
 *
 * Two independent axes, which is the thing about a Jira-shaped board that is
 * easy to get wrong by collapsing them into one:
 *
 * - `board_column_id` is its *status*, and is never null. A backlog item is
 *   already "To do"; it just is not being worked yet.
 * - `sprint_id` is *when*, and null means the backlog. The board draws the
 *   active sprint; the backlog page draws everything else.
 *
 * Folding these together — "the backlog is a column" — breaks the moment a
 * sprint ends: the unfinished work has to go back to the backlog *keeping the
 * status it reached*, and a column-only model has already thrown that away.
 *
 * # number
 *
 * The suffix of the reference people quote: PWA-1499. Allocated from the
 * project's own counter (see the projects table) and never reused, so a
 * reference in a channel from six months ago still points at what it pointed at.
 *
 * # position
 *
 * Sparse, and ordered within whichever list the task is currently in. Dragging a
 * card computes the midpoint between its new neighbours and writes one row —
 * see App\Support\Positions — rather than renumbering the column, because a
 * renumber makes every concurrent drag on the same board a lost update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');

            $table->foreignId('board_column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The epic this task belongs to, if any. Self-referential and
             * exactly one level deep — enforced in App\Actions\Projects\SaveTask,
             * since only an Epic may be named here and an Epic may not name one.
             *
             * nullOnDelete rather than cascade: deleting an epic must not delete
             * the work inside it, which is the whole reason the epic was made.
             */
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('type', 20)->default(TaskType::Story->value);
            $table->string('priority', 20)->default(TaskPriority::Medium->value);

            $table->string('title');
            $table->text('description')->nullable();

            /*
             * Who is doing it, and who filed it.
             *
             * Both nullOnDelete: an unassigned task is a normal state, and a
             * task whose reporter has left the company is still a task. The
             * assignee is additionally constrained to project members by
             * App\Actions\Projects\SaveTask — the database cannot express
             * "exists in project_members for *this* task's project".
             */
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('position');

            $table->unsignedSmallInteger('story_points')->nullable();
            $table->date('due_on')->nullable();

            /*
             * When the task reached a Done column. Written by the move, not
             * derived on read: a column can be recategorized later, and the date
             * a piece of work was actually finished must not move with it.
             */
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['project_id', 'number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_type_check
            CHECK (type IN ('story', 'task', 'bug', 'epic'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_priority_check
            CHECK (priority IN ('highest', 'high', 'medium', 'low', 'lowest'))
        SQL);

        // The board: one column of one sprint, top to bottom. Covers the backlog
        // read too, where sprint_id is null.
        DB::statement('CREATE INDEX tasks_board_index ON tasks (project_id, sprint_id, board_column_id, position)');

        // The backlog list, and everything that walks a project's whole set.
        DB::statement('CREATE INDEX tasks_project_order_index ON tasks (project_id, position)');

        // "What am I working on?" across a workspace's projects.
        DB::statement(<<<'SQL'
            CREATE INDEX tasks_assignee_open_index
            ON tasks (assignee_id, project_id)
            WHERE completed_at IS NULL
        SQL);

        // An epic's children, which the card and the backlog both group by.
        DB::statement('CREATE INDEX tasks_parent_index ON tasks (parent_task_id) WHERE parent_task_id IS NOT NULL');

        // Finding a task by its typed reference — "PWA-1499".
        DB::statement('CREATE INDEX tasks_title_trigram_index ON tasks USING gin ((title::text) gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
