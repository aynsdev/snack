<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Asset library authorization.
 *
 * # One audience, deliberately
 *
 * Unlike a channel, the library has no private shelves. It exists so that the
 * answer to "where do we keep the logo?" is a link rather than a question in
 * #general, and a library you have to be let into answers that question for
 * nobody. So every member of the workspace sees all of it.
 *
 * Guests are the exception, and the only one. A guest is somebody brought in for
 * a single channel — a contractor, a customer — and the library is where the
 * unsigned contract, the price list, and the brand book live. Confining them is
 * the same rule {@see ProjectPolicy} applies for the same reason, spelled as a
 * 404 rather than a 403: whether this workspace keeps a library at all is not
 * something to confirm to somebody who may not read it.
 *
 * # Curating is narrower than reading
 *
 * Anybody can add to the library, because a library only stays useful if adding
 * to it is easier than sending the file in a DM. Changing or removing what is
 * already on the shelf is the curator's own right, or an administrator's —
 * shared structure that anyone can quietly rewrite is shared structure nobody
 * relies on.
 */
class AssetPolicy
{
    /**
     * Browsing the library.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return self::readable($user, $workspace);
    }

    public function view(User $user, Asset $asset): Response
    {
        return self::readable($user, $asset->workspace);
    }

    /**
     * Adding a file to the library.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        return self::readable($user, $workspace)->allowed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Renaming an asset, rewriting its note, or moving it to another shelf.
     */
    public function update(User $user, Asset $asset): Response
    {
        return self::curatable($user, $asset);
    }

    public function delete(User $user, Asset $asset): Response
    {
        return self::curatable($user, $asset);
    }

    /**
     * Who may read the library at all: members, and not guests.
     */
    public static function readable(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        return $role === null || $role->isGuest()
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    /**
     * Whoever put it there, and whoever administers the workspace.
     *
     * The administrator half is not a courtesy: people leave, and a library
     * whose every entry can only be corrected by the person who uploaded it
     * decays into a shelf of things nobody is allowed to throw away.
     */
    private static function curatable(User $user, Asset $asset): Response
    {
        $readable = self::readable($user, $asset->workspace);

        if ($readable->denied()) {
            return $readable;
        }

        if ($user->roleIn($asset->workspace)?->canAdministerWorkspace() === true) {
            return Response::allow();
        }

        return $asset->created_by === $user->id
            ? Response::allow()
            : Response::deny('Only the person who added this asset, or an admin, can change it.');
    }
}
