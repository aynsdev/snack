<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Issues a workspace invitation and emails the recipient a one-time link.
 *
 * The returned invitation carries the plaintext token on its transient
 * `$plainToken` property. That value is never persisted — only its SHA-256 hash
 * is — so this is the single moment it can be surfaced to the inviter.
 */
class InviteWorkspaceMember
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(
        Workspace $workspace,
        User $inviter,
        string $email,
        WorkspaceRole $role = WorkspaceRole::Member,
    ): WorkspaceInvitation {
        $plainToken = WorkspaceInvitation::generateToken();

        $invitation = DB::transaction(function () use ($workspace, $inviter, $email, $role, $plainToken): WorkspaceInvitation {
            // Re-inviting an address with a live invitation replaces it, so the
            // partial unique index on (workspace_id, email) is never violated
            // and only the newest link stays redeemable.
            $workspace->invitations()
                ->outstanding()
                ->where('email', $email)
                ->update([
                    'revoked_at' => now(),
                    'revoked_by' => $inviter->id,
                ]);

            $invitation = $workspace->invitations()->create([
                'invited_by' => $inviter->id,
                'email' => $email,
                'role' => $role,
                'token' => WorkspaceInvitation::hashToken($plainToken),
                'expires_at' => now()->addDays((int) config('snack.invitations.ttl_days')),
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::MemberInvited,
                actor: $inviter,
                auditable: $invitation,
                newValues: ['email' => $email, 'role' => $role->value],
            );

            return $invitation;
        });

        Notification::route('mail', $email)->notify(
            new WorkspaceInvitationNotification($invitation, $plainToken),
        );

        // Transient: a public property, never a model attribute, so it cannot
        // leak into a subsequent write.
        $invitation->plainToken = $plainToken;

        return $invitation;
    }
}
