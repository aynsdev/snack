<?php

namespace App\Policies;

use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Who arranges the project list.
 *
 * The right to make a shelf is the right to make a project — see
 * {@see App\Enums\WorkspaceRole::canCreateProjects()}. The people creating
 * projects are the ones who discover the list has grown past the point of being
 * readable, and making them ask an admin for a heading would mean the list stays
 * unreadable. It is also a low-stakes right: a group holds no work of its own,
 * and deleting one only ungroups the projects that were in it.
 *
 * Guests are excluded, as they are from creating projects. They see only the
 * projects they were added to, and arranging a list they cannot see the whole of
 * is not a thing they can do usefully.
 *
 * Unlike {@see ProjectPolicy}, there is no per-group membership: a group belongs
 * to the workspace, not to a team, so the only question any of these methods
 * asks is what the person's workspace role is.
 */
class ProjectGroupPolicy
{
    /**
     * Reading the group list. Anyone in the workspace, including guests — the
     * headings are how the project list is read, and a guest's projects can sit
     * under them too.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace) === null
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canCreateProjects() === true
            ? Response::allow()
            : Response::deny('You do not have permission to organize projects.');
    }

    /**
     * Renaming a group, and moving it up or down the list.
     */
    public function update(User $user, ProjectGroup $group): Response
    {
        return $this->create($user, $group->workspace);
    }

    /**
     * Deleting a group.
     *
     * The same right as making one, because it destroys nothing: the projects
     * that were in it become ungrouped and are still on the page — see the
     * `nullOnDelete` in the project_groups migration.
     */
    public function delete(User $user, ProjectGroup $group): Response
    {
        return $this->create($user, $group->workspace);
    }
}
