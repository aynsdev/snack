<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\AddProjectMembers;
use App\Actions\Projects\RemoveProjectMember;
use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who is on a project — which is the same question as who can be assigned its
 * tasks.
 *
 * Adding *other* people is this controller; putting yourself on a project is
 * {@see ProjectMembershipController}. Two endpoints rather than one with a
 * parameter, for the reason channels split the same pair: joining is gated on
 * the project being open to you, adding someone else on administering it, and
 * sharing a route would make which policy applies depend on what is in the body.
 *
 * The routes are keyed by the *membership* row rather than by the user, so
 * scoped bindings can tie it to the project in the path. A route keyed by user
 * id would resolve any user in the installation and leave "are they even on this
 * project?" as a check the controller had to remember to make.
 */
class ProjectMemberController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        AddProjectMembers $addMembers,
    ): RedirectResponse {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer'],
            'role' => ['nullable', Rule::enum(ProjectRole::class)],
        ]);

        $added = $addMembers->handle(
            project: $project,
            actor: $request->user(),
            userIds: $validated['user_ids'],
            role: ProjectRole::tryFrom($validated['role'] ?? '') ?? ProjectRole::Member,
        );

        return back()->with('success', match ($added->count()) {
            0 => 'Everyone selected is already on this project.',
            1 => $added->first()->name.' added to the project.',
            default => $added->count().' people added to the project.',
        });
    }

    /**
     * Changes somebody's project role.
     *
     * The last lead cannot be demoted: a project with no lead has nobody who can
     * change its columns, run its sprints, or add anyone — and a workspace admin
     * fixing that from outside is a support ticket, not a design.
     */
    public function update(
        Workspace $workspace,
        Project $project,
        ProjectMember $member,
        Request $request,
    ): RedirectResponse {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ]);

        $role = ProjectRole::from($validated['role']);

        if ($role !== ProjectRole::Lead && $this->isLastLead($project, $member)) {
            return back()->with('error', 'A project needs at least one lead.');
        }

        $member->update(['role' => $role]);

        return back()->with('success', $member->user->name.' is now a '.$role->label().'.');
    }

    public function destroy(
        Workspace $workspace,
        Project $project,
        ProjectMember $member,
        Request $request,
        RemoveProjectMember $removeMember,
    ): RedirectResponse {
        $this->authorize('manageMembers', $project);

        if ($this->isLastLead($project, $member)) {
            return back()->with('error', 'A project needs at least one lead.');
        }

        $user = $member->user;
        $unassigned = $removeMember->handle($project, $request->user(), $user);

        return back()->with('success', $unassigned === 0
            ? $user->name.' removed from the project.'
            : $user->name." removed — {$unassigned} of their open tasks were unassigned.");
    }

    private function isLastLead(Project $project, ProjectMember $member): bool
    {
        if (! $member->isLead()) {
            return false;
        }

        return $project->members()
            ->where('role', ProjectRole::Lead->value)
            ->whereKeyNot($member->id)
            ->doesntExist();
    }
}
