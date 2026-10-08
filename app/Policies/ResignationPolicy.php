<?php

namespace App\Policies;

use App\Enums\ChecklistKind;
use App\Models\Resignation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Anybody employed may resign, once at a time and not while already being
 * offboarded. HR decides — never their own, unless they own the workspace.
 */
class ResignationPolicy
{
    use ManagesPeople;

    public function create(User $user, Workspace $workspace): Response
    {
        if (! $user->belongsToWorkspace($workspace)) {
            return Response::denyAsNotFound();
        }

        $employee = $user->employeeIn($workspace);

        if ($employee?->status->isEmployed() !== true) {
            return Response::deny('You do not have an employee record in this workspace.');
        }

        if ($employee->resignations()->pending()->exists()) {
            return Response::deny('Your resignation is already with HR.');
        }

        return $employee->checklists()->ofKind(ChecklistKind::Offboarding)->inProgress()->exists()
            ? Response::deny('Your offboarding is already under way.')
            : Response::allow();
    }

    public function withdraw(User $user, Resignation $resignation): Response
    {
        if ($resignation->employee->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $resignation->status->isPending()
            ? Response::allow()
            : Response::deny('Only a pending resignation can be withdrawn.');
    }

    public function review(User $user, Resignation $resignation): Response
    {
        $access = $this->managesPeople($user, $resignation->workspace);

        if ($access->denied()) {
            return $access;
        }

        if ($resignation->employee->user_id === $user->id && ! $resignation->workspace->isOwnedBy($user)) {
            return Response::deny('Somebody else has to review your own resignation.');
        }

        return $resignation->status->isPending()
            ? Response::allow()
            : Response::deny('This resignation has already been decided.');
    }
}
