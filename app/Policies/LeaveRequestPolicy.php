<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class LeaveRequestPolicy
{
    use ManagesPeople;

    /**
     * Asking for time off. Anybody with a live employee record may.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        if (! $user->belongsToWorkspace($workspace)) {
            return Response::denyAsNotFound();
        }

        return $user->employeeIn($workspace)?->status->isEmployed() === true
            ? Response::allow()
            : Response::deny('You do not have an employee record in this workspace.');
    }

    /**
     * Withdrawing a request, which only its owner can do and only before it
     * has been decided.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): Response
    {
        if ($leaveRequest->employee->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $leaveRequest->status->isPending()
            ? Response::allow()
            : Response::deny('Only a pending request can be withdrawn.');
    }

    /**
     * Approving or rejecting.
     *
     * An admin may not decide their own request — somebody else signs off on
     * it — unless they own the workspace, in which case there is nobody above
     * them to ask.
     */
    public function review(User $user, LeaveRequest $leaveRequest): Response
    {
        $access = $this->managesPeople($user, $leaveRequest->workspace);

        if ($access->denied()) {
            return $access;
        }

        if ($leaveRequest->employee->user_id === $user->id && ! $leaveRequest->workspace->isOwnedBy($user)) {
            return Response::deny('Somebody else has to review your own leave.');
        }

        return $leaveRequest->status->isPending()
            ? Response::allow()
            : Response::deny('This request has already been decided.');
    }
}
