<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class TimeEntryPolicy
{
    use ManagesPeople;

    /**
     * Everybody's timesheet, which is HR's view.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    /**
     * Clocking in and out, which is your own and needs a live employee record.
     */
    public function clock(User $user, Workspace $workspace): Response
    {
        if (! $user->belongsToWorkspace($workspace)) {
            return Response::denyAsNotFound();
        }

        return $user->employeeIn($workspace)?->status->isEmployed() === true
            ? Response::allow()
            : Response::deny('You do not have an employee record in this workspace.');
    }

    /**
     * Correcting a shift after the fact, which is HR's call.
     */
    public function update(User $user, TimeEntry $timeEntry): Response
    {
        return $this->managesPeople($user, $timeEntry->workspace);
    }

    public function delete(User $user, TimeEntry $timeEntry): Response
    {
        return $this->managesPeople($user, $timeEntry->workspace);
    }
}
