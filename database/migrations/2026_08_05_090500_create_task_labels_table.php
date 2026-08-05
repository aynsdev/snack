<?php

use App\Enums\TaskType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The labels a project has defined, and which tasks wear them.
 *
 * Labels are the customizable half of a card: {@see TaskType} is a
 * fixed set because the application reasons about it, and this is the part a
 * team invents for itself — "needs design", "customer-reported", "tech debt".
 * Scoped to the project rather than the workspace so one team's vocabulary does
 * not clutter another team's picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // A palette token, matching projects.color — one definition of the
            // colours in the client, and no second column for dark mode.
            $table->string('color', 20)->default('slate');

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE task_labels ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE task_labels ADD CONSTRAINT task_labels_project_name_unique UNIQUE (project_id, name)');

        Schema::create('task_label_task', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_label_id')->constrained('task_labels')->cascadeOnDelete();

            $table->primary(['task_id', 'task_label_id']);
        });

        // Filtering a board by label reads from the label side.
        DB::statement('CREATE INDEX task_label_task_label_index ON task_label_task (task_label_id, task_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_label_task');
        Schema::dropIfExists('task_labels');
    }
};
