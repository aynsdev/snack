<?php

namespace App\Policies;

use App\Models\EmployeeChecklist;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * HR runs onboarding and offboarding. Everybody else only works the steps
 * given to them — see {@see EmployeeChecklistTaskPolicy}.
 */
class EmployeeChecklistPolicy
{
    use ManagesPeople;

    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    /**
     * Completing or cancelling.
     */
    public function close(User $user, EmployeeChecklist $checklist): Response
    {
        $access = $this->managesPeople($user, $checklist->workspace);

        if ($access->denied()) {
            return $access;
        }

        return $checklist->status->isInProgress()
            ? Response::allow()
            : Response::deny('This checklist is already closed.');
    }
}
