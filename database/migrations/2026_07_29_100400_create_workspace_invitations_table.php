<?php

use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation-first onboarding.
 *
 * `token` stores a SHA-256 hash of the value that appears in the invite URL, so
 * a database leak alone cannot be replayed into workspace access. The plaintext
 * is shown to the inviter exactly once, at creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            $table->string('email');
            $table->string('role', 20)->default(WorkspaceRole::Member->value);

            $table->string('token', 64)->unique();

            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // Admin screen: "pending invitations for this workspace".
            $table->index(['workspace_id', 'expires_at']);
        });

        DB::statement('ALTER TABLE workspace_invitations ALTER COLUMN email TYPE citext');

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_invitations
            ADD CONSTRAINT workspace_invitations_role_check
            CHECK (role IN ('admin', 'member', 'guest'))
        SQL);

        // One live invitation per email per workspace. Accepted and revoked rows
        // are excluded so the same address can be re-invited later.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_invitations_pending_unique
            ON workspace_invitations (workspace_id, email)
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
