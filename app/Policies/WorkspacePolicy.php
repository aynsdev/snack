<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Access\Response;

/**
 * Workspace-level authorization.
 *
 * Every check begins by resolving the acting user's role in *this* workspace. A
 * role held elsewhere confers nothing here, which is what keeps tenants
 * isolated once Snack becomes multi-tenant.
 *
 * Denials use `denyAsNotFound()` wherever confirming that a record exists would
 * itself leak information.
 */
class WorkspacePolicy
{
    /**
     * Anyone signed in may see their own workspace list; the underlying query
     * is scoped to their memberships rather than filtered here.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): Response
    {
        return $user->belongsToWorkspace($workspace)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canAdministerWorkspace() === true
            ? Response::allow()
            : Response::deny('Only workspace admins can change workspace settings.');
    }

    /**
     * Deleting a workspace takes every message with it, so it is reserved for
     * the owner — an admin cannot delete the workspace out from under them.
     */
    public function delete(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can delete this workspace.');
    }

    public function transferOwnership(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can transfer ownership.');
    }

    // -- Members -------------------------------------------------------------

    /**
     * Guests do not get the member directory; they only ever see the people in
     * the channels they were explicitly added to.
     */
    public function viewMembers(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role->isGuest()
            ? Response::deny('Guests cannot browse the member directory.')
            : Response::allow();
    }

    public function inviteMembers(User $user, Workspace $workspace): Response
    {
        if ($user->roleIn($workspace)?->canManageMembers() !== true) {
            return Response::deny('Only workspace admins can invite people.');
        }

        if (! $workspace->allowsInvitations()) {
            return Response::deny('Invitations are currently disabled for this workspace.');
        }

        return $workspace->hasReachedMemberLimit()
            ? Response::deny('This workspace has reached its member limit.')
            : Response::allow();
    }

    /**
     * Guards role changes, suspensions, and removals.
     *
     * Admins may only act on members ranked below them, and nobody may act on
     * themselves through the admin screens — leaving a workspace is a separate,
     * deliberately different action.
     */
    public function manageMember(User $user, Workspace $workspace, WorkspaceMember $target): Response
    {
        $actor = $user->membershipIn($workspace);

        if ($actor === null || ! $actor->isActive()) {
            return Response::denyAsNotFound();
        }

        if (! $actor->role->canManageMembers()) {
            return Response::deny('Only workspace admins can manage members.');
        }

        if ($actor->id === $target->id) {
            return Response::deny('You cannot change your own membership here.');
        }

        if ($target->isOwner()) {
            return Response::deny('The workspace owner cannot be modified.');
        }

        // An admin may manage members and guests but not a fellow admin; only
        // the owner outranks an admin.
        return $actor->outranks($target)
            ? Response::allow()
            : Response::deny('You cannot manage another admin.');
    }

    // -- Administration ------------------------------------------------------

    public function viewAuditLogs(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canViewAuditLogs() === true
            ? Response::allow()
            : Response::deny('Only workspace admins can view the audit log.');
    }

    public function exportData(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can export workspace data.');
    }
}
