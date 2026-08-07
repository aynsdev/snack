<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Project authorization.
 *
 * # Two different questions
 *
 * Reading a board and working it are separate rights here, exactly as previewing
 * a public channel and posting in it are. Anyone in the workspace can open a
 * project and see where the work stands — a board nobody outside the team can
 * look at is how a company ends up asking in #general what shipped — but only
 * the people *on* the project can move a card, file one, or be handed one.
 *
 * That second set is {@see Project::members()}, and it is the rule the brief
 * names: people are assigned tasks on the projects they are on.
 *
 * Guests are the exception to the first half. They are confined to what they
 * were explicitly added to, so for them membership is required to see the
 * project at all.
 */
class ProjectPolicy
{
    /**
     * Browsing the project list. Guests have none — they see only the projects
     * they were added to, which the list itself is built from.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace) === null
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    public function view(User $user, Project $project): Response
    {
        $role = $user->roleIn($project->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($project->hasMember($user)) {
            return Response::allow();
        }

        return $role->isGuest()
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canCreateProjects() === true
            ? Response::allow()
            : Response::deny('You do not have permission to create projects.');
    }

    /**
     * Working the board: filing a task, moving one, editing one.
     *
     * Membership, not workspace rank — with workspace admins let through so a
     * project whose lead has left is never stranded. An archived project is
     * read-only for everybody: that is what archiving it means.
     */
    public function contribute(User $user, Project $project): Response
    {
        $visible = $this->view($user, $project);

        if ($visible->denied()) {
            return $visible;
        }

        if ($project->is_archived) {
            return Response::deny('This project is archived.');
        }

        $role = $user->roleIn($project->workspace);

        if ($role?->canAdministerWorkspace() === true) {
            return Response::allow();
        }

        return $project->hasMember($user)
            ? Response::allow()
            : Response::deny('Join this project to work on its board.');
    }

    /**
     * Changing what the project *is*: its name, its columns, its labels, its
     * card fields, and who is on it.
     */
    public function update(User $user, Project $project): Response
    {
        $rights = $this->managementRights($user, $project);

        if ($rights->denied()) {
            return $rights;
        }

        return $project->is_archived
            ? Response::deny('Archived projects cannot be edited.')
            : Response::allow();
    }

    /**
     * Archiving, and restoring.
     *
     * Deliberately not delegating to {@see update()}, which refuses archived
     * projects — routing this through it would make an archived project
     * permanently unrestorable. The same split {@see ChannelPolicy::archive()}
     * makes, for the same reason.
     */
    public function archive(User $user, Project $project): Response
    {
        return $this->managementRights($user, $project);
    }

    /**
     * Adding and removing people, and changing their project role.
     */
    public function manageMembers(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }

    /**
     * Planning, starting, and completing sprints.
     *
     * A lead's right rather than a member's: starting a sprint moves every
     * committed task onto the board at once, and completing one rolls whatever
     * is unfinished forward. Both change what the whole team is looking at.
     */
    public function manageSprints(User $user, Project $project): Response
    {
        return $this->update($user, $project);
    }

    /**
     * Joining under one's own steam.
     *
     * Anyone who can already see the board can put themselves on it, which is
     * what makes a project findable rather than a room you have to be let into.
     * Guests cannot: {@see view()} already refuses them any project they are not
     * a member of, so there is no project here for them to join.
     */
    public function join(User $user, Project $project): Response
    {
        $visible = $this->view($user, $project);

        if ($visible->denied()) {
            return $visible;
        }

        if ($project->hasMember($user)) {
            return Response::deny('You are already on this project.');
        }

        return $project->is_archived
            ? Response::deny('This project is archived.')
            : Response::allow();
    }

    /**
     * Keeping a project at the top of one's own sidebar.
     *
     * A member's right, and the lightest one here: a star changes nothing
     * anybody else can see, which is why it is not gated on contributing. It
     * does need membership — the sidebar list a star reorders is the list of
     * projects you are on.
     */
    public function star(User $user, Project $project): Response
    {
        return $project->hasMember($user)
            ? Response::allow()
            : Response::deny('Join this project to star it.');
    }

    public function leave(User $user, Project $project): Response
    {
        return $project->hasMember($user)
            ? Response::allow()
            : Response::deny('You are not on this project.');
    }

    /**
     * Who may administer this project at all, ignoring its archived state.
     *
     * Workspace admins always qualify; otherwise the project's own leads do.
     */
    private function managementRights(User $user, Project $project): Response
    {
        $role = $user->roleIn($project->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($role->canAdministerWorkspace()) {
            return Response::allow();
        }

        return $project->membershipFor($user)?->isLead() === true
            ? Response::allow()
            : Response::deny('Only project leads can change this project.');
    }
}
