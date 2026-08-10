<?php

namespace App\Policies;

use App\Models\Canvas;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class CanvasPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return self::readable($user, $workspace);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Canvas $canvas): Response
    {
        return self::viewable($user, $canvas);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        return self::readable($user, $workspace);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Canvas $canvas): Response
    {
        $viewable = self::viewable($user, $canvas);

        if ($viewable->denied()) {
            return $viewable;
        }

        if ($canvas->channel_id !== null) {
            return $user->can('post', $canvas->channel)
                ? Response::allow()
                : Response::deny('Join the channel to edit this Canvas.');
        }

        if ($canvas->conversation_id !== null) {
            return $user->can('post', $canvas->conversation)
                ? Response::allow()
                : Response::deny('You can no longer edit this Canvas.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Canvas $canvas): Response
    {
        $readable = self::viewable($user, $canvas);

        if ($readable->denied()) {
            return $readable;
        }

        if ($user->roleIn($canvas->workspace)?->canAdministerWorkspace() === true) {
            return Response::allow();
        }

        return $canvas->created_by === $user->id
            ? Response::allow()
            : Response::deny('Only the canvas creator or a workspace admin can delete it.');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Canvas $canvas): Response
    {
        return Response::deny();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Canvas $canvas): Response
    {
        return Response::deny();
    }

    private static function readable(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        return $role === null || $role->isGuest()
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    private static function viewable(User $user, Canvas $canvas): Response
    {
        $readable = self::readable($user, $canvas->workspace);

        if ($readable->denied()) {
            return $readable;
        }

        if ($canvas->channel_id !== null && ! $user->can('view', $canvas->channel)) {
            return Response::denyAsNotFound();
        }

        if ($canvas->conversation_id !== null && ! $user->can('view', $canvas->conversation)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
