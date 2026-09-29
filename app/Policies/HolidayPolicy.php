<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Everybody in the workspace reads these; HR writes them.
 */
class HolidayPolicy
{
    use ManagesPeople;

    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->belongsTo($user, $workspace);
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function update(User $user, Holiday $model): Response
    {
        return $this->managesPeople($user, $model->workspace);
    }

    public function delete(User $user, Holiday $model): Response
    {
        return $this->managesPeople($user, $model->workspace);
    }
}
