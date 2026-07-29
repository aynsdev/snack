<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every other Snack table hangs off a workspace, either
 * directly via `workspace_id` or transitively through a channel/conversation.
 *
 * New Snack tables use `timestamptz` throughout so that instants are
 * unambiguous regardless of the server's local zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();

            $table->unsignedInteger('member_limit')->default(500);

            // Free-form workspace configuration: retention, auth policy,
            // upload limits, integration toggles. Queried with JSONB operators.
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();
        });

        // Slugs appear in URLs (/w/{slug}) and must be case-insensitively unique.
        DB::statement('ALTER TABLE workspaces ALTER COLUMN slug TYPE citext');
        DB::statement('ALTER TABLE workspaces ADD CONSTRAINT workspaces_slug_unique UNIQUE (slug)');

        DB::statement('CREATE INDEX workspaces_settings_index ON workspaces USING gin (settings jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
