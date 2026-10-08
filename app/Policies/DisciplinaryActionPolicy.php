<?php

namespace App\Policies;

use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * HR issues and withdraws discipline. The employee may read their own —
 * through {@see EmployeePolicy::view()}, like the rest of their file.
 */
class DisciplinaryActionPolicy
{
    use ManagesPeople;

    public function create(User $user, Employee $employee): Response
    {
        return $this->managesPeople($user, $employee->workspace);
    }

    public function delete(User $user, DisciplinaryAction $action): Response
    {
        return $this->managesPeople($user, $action->employee->workspace);
    }
}
