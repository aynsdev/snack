<?php

namespace App\Policies;

use App\Models\AssetFolder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Asset folder authorization.
 *
 * Reading follows {@see AssetPolicy::readable()} exactly — a shelf and the
 * things on it have one audience, and a tree whose folders and contents could
 * disagree about who sees them would show people breadcrumbs to places they
 * cannot open.
 *
 * Deleting is where this parts company with the asset policy. Removing a folder
 * removes everything inside it, including assets other people curated, so it is
 * not the folder creator's own affair the way renaming their own upload is —
 * see {@see delete()}.
 */
class AssetFolderPolicy
{
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return AssetPolicy::readable($user, $workspace);
    }

    public function view(User $user, AssetFolder $folder): Response
    {
        return AssetPolicy::readable($user, $folder->workspace);
    }

    /**
     * Making a shelf.
     *
     * As open as adding a file, and for the same reason: a library where only
     * administrators may create folders is one where everything ends up at the
     * top level, because that is the only place people can put things.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        return AssetPolicy::readable($user, $workspace)->allowed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Renaming a folder, rewriting its description, or moving it.
     */
    public function update(User $user, AssetFolder $folder): Response
    {
        $readable = AssetPolicy::readable($user, $folder->workspace);

        if ($readable->denied()) {
            return $readable;
        }

        if ($user->roleIn($folder->workspace)?->canAdministerWorkspace() === true) {
            return Response::allow();
        }

        return $folder->created_by === $user->id
            ? Response::allow()
            : Response::deny('Only the person who created this folder, or an admin, can change it.');
    }

    /**
     * Deleting a folder, and with it everything filed under it.
     *
     * Deliberately stricter than {@see update()}. A rename is reversible and
     * touches one row; a delete cascades through every subfolder and every asset
     * inside them, most of which the person deleting did not put there. So an
     * empty folder is the creator's to remove, and anything with contents is an
     * administrator's call — the same shape as needing a lead to archive a
     * project rather than a member.
     */
    public function delete(User $user, AssetFolder $folder): Response
    {
        $rights = $this->update($user, $folder);

        if ($rights->denied()) {
            return $rights;
        }

        if ($user->roleIn($folder->workspace)?->canAdministerWorkspace() === true) {
            return Response::allow();
        }

        return $folder->isEmpty()
            ? Response::allow()
            : Response::deny('Only an admin can delete a folder that still has things in it.');
    }
}
