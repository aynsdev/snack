<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\Response;

class WorkspaceInvitationPolicy
{
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canManageMembers() === true
            ? Response::allow()
            : Response::deny('Only workspace admins can view invitations.');
    }

    /**
     * Revoking an invitation that has already been accepted would do nothing
     * except confuse the admin, so it is rejected explicitly.
     */
    public function revoke(User $user, WorkspaceInvitation $invitation): Response
    {
        if ($user->roleIn($invitation->workspace)?->canManageMembers() !== true) {
            return Response::deny('Only workspace admins can revoke invitations.');
        }

        if ($invitation->isAccepted()) {
            return Response::deny('This invitation has already been accepted.');
        }

        return $invitation->isRevoked()
            ? Response::deny('This invitation has already been revoked.')
            : Response::allow();
    }

    /**
     * Re-sending is only meaningful while the invitation is still live.
     */
    public function resend(User $user, WorkspaceInvitation $invitation): Response
    {
        if ($user->roleIn($invitation->workspace)?->canManageMembers() !== true) {
            return Response::deny('Only workspace admins can resend invitations.');
        }

        return $invitation->isAccepted() || $invitation->isRevoked()
            ? Response::deny('This invitation is no longer active.')
            : Response::allow();
    }

    /**
     * Accepting is bound to the invited address, so a forwarded link cannot be
     * redeemed by a different account.
     */
    public function accept(User $user, WorkspaceInvitation $invitation): Response
    {
        if (! $invitation->isPending()) {
            return Response::deny('This invitation is no longer valid.');
        }

        if (! $invitation->isAddressedTo($user)) {
            return Response::deny('This invitation was sent to a different email address.');
        }

        return $user->belongsToWorkspace($invitation->workspace)
            ? Response::deny('You are already a member of this workspace.')
            : Response::allow();
    }
}
