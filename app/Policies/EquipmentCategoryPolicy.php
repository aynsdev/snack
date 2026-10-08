<?php

namespace App\Policies;

use App\Models\EquipmentCategory;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * HR names the kinds of equipment there are.
 */
class EquipmentCategoryPolicy
{
    use ManagesPeople;

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function update(User $user, EquipmentCategory $category): Response
    {
        return $this->managesPeople($user, $category->workspace);
    }

    public function delete(User $user, EquipmentCategory $category): Response
    {
        return $this->managesPeople($user, $category->workspace);
    }
}
