<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A fifth kind of card: the subtask.
 *
 * The same as Jira's — a piece of one story, task, or bug, which always has
 * that parent, never has children of its own, and moves with its parent from
 * sprint to sprint. The rules live in SaveTask and MoveTask; the database only
 * has to accept the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_type_check
            CHECK (type IN ('story', 'task', 'bug', 'epic', 'subtask'))
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE tasks SET type = 'task' WHERE type = 'subtask'");
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_type_check
            CHECK (type IN ('story', 'task', 'bug', 'epic'))
        SQL);
    }
};
