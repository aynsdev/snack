<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace-scoped roles live here rather than on `users`, so that one account
 * can be an Owner in one workspace and a Guest in another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default(WorkspaceRole::Member->value);
            $table->string('status', 20)->default(MemberStatus::Active->value);

            // Workspace-wide notification defaults, overridden per channel by
            // `channel_members.notification_level`.
            $table->jsonb('notification_preferences')->default(DB::raw("'{}'::jsonb"));

            $table->timestampTz('joined_at');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);

            // "Which workspaces am I in?" — drives the workspace rail.
            $table->index(['user_id', 'status']);

            // Member directory and admin screens, filtered by role.
            $table->index(['workspace_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_members
            ADD CONSTRAINT workspace_members_role_check
            CHECK (role IN ('owner', 'admin', 'member', 'guest'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_members
            ADD CONSTRAINT workspace_members_status_check
            CHECK (status IN ('active', 'suspended'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
