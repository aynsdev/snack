<?php

namespace App\Policies;

use App\Models\Equipment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * HR keeps the equipment list. Everybody sees what is handed to them through
 * their own page, not this one.
 */
class EquipmentPolicy
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

    public function update(User $user, Equipment $equipment): Response
    {
        return $this->managesPeople($user, $equipment->workspace);
    }

    public function delete(User $user, Equipment $equipment): Response
    {
        return $this->managesPeople($user, $equipment->workspace);
    }
}
