<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Employee files are HR's to read and write. The one exception is your own:
 * everybody may see their own profile, contracts, and history.
 */
class EmployeePolicy
{
    use ManagesPeople;

    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function view(User $user, Employee $employee): Response
    {
        if ($employee->user_id === $user->id && $user->belongsToWorkspace($employee->workspace)) {
            return Response::allow();
        }

        // Not found rather than forbidden: an employee id should not confirm
        // to a colleague that the file exists.
        return $this->managesPeople($user, $employee->workspace)->allowed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Editing the file, and everything filed on it — history, contracts,
     * records, absences.
     */
    public function update(User $user, Employee $employee): Response
    {
        return $this->managesPeople($user, $employee->workspace);
    }
}
