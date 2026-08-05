<?php

use App\Models\Sprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fixed window of work.
 *
 * # Why there is no `state` column
 *
 * A sprint is planned, then active, then completed, and each transition is
 * exactly a timestamp being written. Storing the word as well would give the
 * burndown two things to believe — and the one that is wrong is always the one
 * being read. {@see Sprint::state()} derives it from the dates.
 *
 * # One active sprint
 *
 * Enforced by a partial unique index rather than by the action that starts one,
 * for the reason every other invariant in Snack is enforced in the schema: two
 * simultaneous "Start sprint" clicks are one request apart, and a board that
 * shows the union of two active sprints is a board nobody can plan against.
 *
 * `starts_at` and `ends_at` are the dates the team *intends*; `started_at` and
 * `completed_at` are when it actually happened. A sprint that runs three days
 * long should say so, not have its plan quietly rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('goal')->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // Where this sprint sits among the planned ones in the backlog.
            $table->integer('position');

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sprints_one_active_per_project
            ON sprints (project_id)
            WHERE started_at IS NOT NULL AND completed_at IS NULL
        SQL);

        // The backlog: this project's sprints, in planning order.
        DB::statement('CREATE INDEX sprints_project_order_index ON sprints (project_id, position)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sprints');
    }
};
