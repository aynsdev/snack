<?php

namespace App\Policies;

use App\Models\EquipmentAssignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The holder may ask to give an item back; HR records it coming back.
 */
class EquipmentAssignmentPolicy
{
    use ManagesPeople;

    public function requestReturn(User $user, EquipmentAssignment $assignment): Response
    {
        if ($assignment->employee->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        if (! $assignment->isOpen()) {
            return Response::deny('This has already been returned.');
        }

        return $assignment->return_requested_at === null
            ? Response::allow()
            : Response::deny('HR already knows you want to return this.');
    }

    public function takeBack(User $user, EquipmentAssignment $assignment): Response
    {
        $access = $this->managesPeople($user, $assignment->equipment->workspace);

        if ($access->denied()) {
            return $access;
        }

        return $assignment->isOpen()
            ? Response::allow()
            : Response::deny('This has already been returned.');
    }
}
