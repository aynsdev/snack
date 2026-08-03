<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Issues the workspace's shareable invite link.
 *
 * The counterpart of {@see InviteWorkspaceMember}: same table, same token
 * scheme, same landing page — the difference is that the row names no
 * addressee, so anybody holding the URL can redeem it. See the migration that
 * added `max_uses` for why that is one column rather than one table.
 *
 * # Regenerating is revoking
 *
 * A workspace has exactly one live link, guarded by a partial unique index.
 * Issuing a new one revokes the old in the same transaction, which is what
 * makes "regenerate" a meaningful safety control: an admin who thinks the link
 * has leaked gets a new URL *and* the certainty that the old one is dead, in one
 * action, without having to find and revoke it separately.
 *
 * # Why guests cannot be invited by link
 *
 * A guest is deliberately not auto-joined to any channel — an admin adds them to
 * the specific ones they are there for — so a guest arriving unannounced through
 * a shared URL lands in a workspace with nothing in it and no one expecting
 * them. The role is rejected here rather than silently rewritten, so an admin
 * who tries it finds out.
 */
class CreateWorkspaceInviteLink
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  int|null  $maxUses  Null for unlimited within the expiry window.
     */
    public function handle(
        Workspace $workspace,
        User $creator,
        WorkspaceRole $role = WorkspaceRole::Member,
        ?int $maxUses = null,
        ?int $ttlDays = null,
    ): WorkspaceInvitation {
        $plainToken = WorkspaceInvitation::generateToken();

        $invitation = DB::transaction(function () use ($workspace, $creator, $role, $maxUses, $ttlDays, $plainToken): WorkspaceInvitation {
            $this->revokeExistingLink($workspace, $creator);

            $invitation = $workspace->invitations()->create([
                'invited_by' => $creator->id,
                // The whole point: addressed to nobody.
                'email' => null,
                'role' => $role,
                'max_uses' => $maxUses,
                'use_count' => 0,
                'token' => WorkspaceInvitation::hashToken($plainToken),
                // Kept, encrypted, because the admin screen is this link's only
                // delivery mechanism and "copy link" has to work next week too.
                'link_token' => $plainToken,
                'expires_at' => now()->addDays(
                    $ttlDays ?? (int) config('snack.invitations.link_ttl_days'),
                ),
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::InviteLinkCreated,
                actor: $creator,
                auditable: $invitation,
                newValues: ['role' => $role->value, 'max_uses' => $maxUses],
            );

            return $invitation;
        });

        // Transient: a public property, never a model attribute, so it cannot
        // leak into a subsequent write. This is the only moment the plaintext
        // can be surfaced — and for a link, unlike an email, surfacing it to the
        // admin is the entire delivery mechanism.
        $invitation->plainToken = $plainToken;

        return $invitation;
    }

    /**
     * Retire whatever link the workspace had.
     *
     * Revoked rather than deleted: the row is what an accepted membership's
     * audit entry points at, and removing it would leave the log referring to
     * something that no longer exists.
     */
    private function revokeExistingLink(Workspace $workspace, User $creator): void
    {
        $workspace->invitations()
            ->links()
            ->outstanding()
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $creator->id,
            ]);
    }
}
