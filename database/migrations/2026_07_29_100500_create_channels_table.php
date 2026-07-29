<?php

use App\Enums\ChannelType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `is_private` is derived from `type` but materialized, because the sidebar and
 * the channel browser filter on it constantly and a stored boolean keeps those
 * partial indexes cheap.
 *
 * It is a PostgreSQL *generated* column rather than an application-maintained
 * one. Deriving it in a model event would silently drift the moment anything
 * writes with events muted — a seeder using WithoutModelEvents, a bulk insert,
 * or saveQuietly() — and drift here means a private channel indexed as public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('topic')->nullable();

            $table->string('type', 20)->default(ChannelType::Public->value);
            $table->boolean('is_private')->storedAs("type = 'private'");
            $table->boolean('is_archived')->default(false);

            // Default channels are auto-joined by every new workspace member.
            $table->boolean('is_default')->default(false);

            $table->timestampTz('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE channels ALTER COLUMN slug TYPE citext');
        DB::statement('ALTER TABLE channels ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE channels ADD CONSTRAINT channels_workspace_slug_unique UNIQUE (workspace_id, slug)');

        DB::statement(<<<'SQL'
            ALTER TABLE channels
            ADD CONSTRAINT channels_type_check
            CHECK (type IN ('public', 'private', 'announcement'))
        SQL);

        // Channel browser: active, joinable channels for a workspace.
        DB::statement(<<<'SQL'
            CREATE INDEX channels_browsable_index
            ON channels (workspace_id, name)
            WHERE is_archived = false AND is_private = false
        SQL);

        // Sidebar ordering by recency.
        DB::statement('CREATE INDEX channels_workspace_recent_index ON channels (workspace_id, last_message_at DESC NULLS LAST)');

        // Channels auto-joined on workspace join.
        DB::statement('CREATE INDEX channels_defaults_index ON channels (workspace_id) WHERE is_default = true');

        // Fuzzy channel search / quick switcher.
        DB::statement('CREATE INDEX channels_name_trigram_index ON channels USING gin ((name::text) gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
