<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * The two questions every HR policy starts from: is this person in the
 * workspace at all, and do they run HR here.
 */
trait ManagesPeople
{
    protected function managesPeople(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role->canManagePeople()
            ? Response::allow()
            : Response::deny('Only workspace admins can manage HR.');
    }

    protected function belongsTo(User $user, Workspace $workspace): Response
    {
        return $user->belongsToWorkspace($workspace)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
