<?php

namespace App\Policies;

use App\Models\CompanyPolicy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Everybody in the workspace reads the handbook; HR writes it. Acknowledging
 * a policy takes an employee record, since it is filed against one.
 */
class CompanyPolicyPolicy
{
    use ManagesPeople;

    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->belongsTo($user, $workspace);
    }

    public function view(User $user, CompanyPolicy $policy): Response
    {
        return $this->belongsTo($user, $policy->workspace);
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function update(User $user, CompanyPolicy $policy): Response
    {
        return $this->managesPeople($user, $policy->workspace);
    }

    public function delete(User $user, CompanyPolicy $policy): Response
    {
        return $this->managesPeople($user, $policy->workspace);
    }

    public function acknowledge(User $user, CompanyPolicy $policy): Response
    {
        if (! $user->belongsToWorkspace($policy->workspace)) {
            return Response::denyAsNotFound();
        }

        if (! $policy->requires_acknowledgement) {
            return Response::deny('This policy does not need acknowledging.');
        }

        return $user->employeeIn($policy->workspace)?->status->isEmployed() === true
            ? Response::allow()
            : Response::deny('You do not have an employee record in this workspace.');
    }
}
