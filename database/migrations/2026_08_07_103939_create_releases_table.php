<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A version of the thing being built, and the work that goes out in it.
 *
 * # Why this is not a sprint
 *
 * A sprint is *when a team works*; a release is *what ships*. They are different
 * axes and a task belongs to one of each: work planned for this fortnight can
 * be held back for next month's release, and a release usually gathers work
 * from several sprints. Modelling either as the other loses that the first time
 * a fix is cherry-picked forward — the same reason `sprint_id` and
 * `board_column_id` are separate columns on a task.
 *
 * # Dates
 *
 * `starts_on` and `ends_on` are dates rather than timestamps because a release
 * window is spoken about in days — "the March release" — and storing a time
 * would make the answer depend on the reader's zone.
 *
 * The name is unique per project, citext like project keys and group names: a
 * team quoting "1.4.0" must not have to ask which 1.4.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            /*
             * The release notes. Free text, written up as the release fills,
             * and the reason a release is a row rather than a label: it is a
             * document as much as a bucket.
             */
            $table->text('notes')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE releases ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE releases ADD CONSTRAINT releases_project_name_unique UNIQUE (project_id, name)');

        // The releases page: this project's releases, soonest last.
        DB::statement('CREATE INDEX releases_project_dates_index ON releases (project_id, ends_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
