<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project: a board, a backlog, and the people who work them.
 *
 * # The key
 *
 * `key` is the short prefix every task in the project is referenced by — PWA-1499
 * — and it is what people type, say out loud, and paste into a channel. It is
 * unique per workspace and citext, matching how channel slugs are stored, so
 * "pwa-1499" and "PWA-1499" name the same task.
 *
 * `next_task_number` is the counter that key's suffix comes from. It lives here
 * rather than being derived with `MAX(number) + 1` because deleting the newest
 * task would otherwise hand its number to the next one created, and a reference
 * that used to mean one thing and now means another is worse than a gap.
 *
 * # card_fields
 *
 * Which parts of a task the board draws on its cards. A project that does not
 * estimate should not have an empty points slot on every card, and a two-person
 * project has little use for an assignee avatar that is always the same face.
 * Stored as jsonb and read through {@see Project::cardFields()},
 * which fills in defaults for keys that predate a later addition — so a project
 * created today keeps rendering correctly when a new toggle ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('key', 10);
            $table->text('description')->nullable();

            // The accent the project is drawn in: its avatar, its cards' left
            // edge. A token name rather than a hex value, so the palette stays
            // one definition in the client and dark mode is not a second column.
            $table->string('color', 20)->default('violet');

            /*
             * Whether this project plans in sprints.
             *
             * A kanban team has a board and a backlog and no ceremony around
             * them; a scrum team commits to a set of work for two weeks. The
             * difference is one flag rather than two project types because
             * everything else — columns, cards, assignment — is identical, and
             * teams switch.
             */
            $table->boolean('uses_sprints')->default(true);

            $table->boolean('is_archived')->default(false);

            $table->unsignedInteger('next_task_number')->default(1);

            $table->jsonb('card_fields')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE projects ALTER COLUMN slug TYPE citext');
        DB::statement('ALTER TABLE projects ALTER COLUMN key TYPE citext');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_workspace_slug_unique UNIQUE (workspace_id, slug)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_workspace_key_unique UNIQUE (workspace_id, key)');

        // The project list, which only ever shows live projects.
        DB::statement(<<<'SQL'
            CREATE INDEX projects_workspace_active_index
            ON projects (workspace_id, name)
            WHERE is_archived = false
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
