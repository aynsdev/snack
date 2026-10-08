<?php

namespace App\Policies;

use App\Models\EmployeeChecklistTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A step is ticked off by the person it was given to, or by HR.
 */
class EmployeeChecklistTaskPolicy
{
    use ManagesPeople;

    public function toggle(User $user, EmployeeChecklistTask $task): Response
    {
        $checklist = $task->checklist;

        if ($task->assignee_id !== $user->id && $this->managesPeople($user, $checklist->workspace)->denied()) {
            return Response::denyAsNotFound();
        }

        return $checklist->status->isInProgress()
            ? Response::allow()
            : Response::deny('This checklist is already closed.');
    }
}
