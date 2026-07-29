<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WorkspaceInvitation;

/**
 * Invalidates an outstanding invitation link.
 *
 * The row is kept rather than deleted so the audit trail retains who invited
 * whom, and when it was withdrawn.
 */
class RevokeWorkspaceInvitation
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(WorkspaceInvitation $invitation, User $actor): WorkspaceInvitation
    {
        $invitation->update([
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        $this->audit->handle(
            workspace: $invitation->workspace,
            action: AuditAction::MemberInvitationRevoked,
            actor: $actor,
            auditable: $invitation,
            oldValues: ['email' => $invitation->email, 'role' => $invitation->role->value],
        );

        return $invitation;
    }
}
