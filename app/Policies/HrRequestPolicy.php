<?php

namespace App\Policies;

use App\Models\HrRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * The rules every HR request shares, whatever is being asked for: anybody
 * employed may ask, only the asker may withdraw, and HR decides — never on
 * their own request unless they own the workspace and there is nobody above
 * them to ask. The same rules leave follows; see {@see LeaveRequestPolicy}.
 *
 * Each request model has its own subclass so Laravel finds it by name.
 */
abstract class HrRequestPolicy
{
    use ManagesPeople;

    public function create(User $user, Workspace $workspace): Response
    {
        if (! $user->belongsToWorkspace($workspace)) {
            return Response::denyAsNotFound();
        }

        return $user->employeeIn($workspace)?->status->isEmployed() === true
            ? Response::allow()
            : Response::deny('You do not have an employee record in this workspace.');
    }

    public function cancel(User $user, HrRequest $request): Response
    {
        if ($request->employee->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $request->status->isPending()
            ? Response::allow()
            : Response::deny('Only a pending request can be withdrawn.');
    }

    public function review(User $user, HrRequest $request): Response
    {
        $access = $this->managesPeople($user, $request->workspace);

        if ($access->denied()) {
            return $access;
        }

        if ($request->employee->user_id === $user->id && ! $request->workspace->isOwnedBy($user)) {
            return Response::deny('Somebody else has to review your own request.');
        }

        return $request->status->isPending()
            ? Response::allow()
            : Response::deny('This request has already been decided.');
    }
}
