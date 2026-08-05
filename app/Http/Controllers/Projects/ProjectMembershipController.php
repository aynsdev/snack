<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\AddProjectMembers;
use App\Actions\Projects\RemoveProjectMember;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting yourself on a project, and taking yourself off it.
 *
 * A singleton pair on the project rather than a resource keyed by user: the only
 * person these can ever act on is the one making the request, and an id in the
 * path would invite one that names somebody else. That is
 * {@see ProjectMemberController}, which is gated differently.
 */
class ProjectMembershipController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        AddProjectMembers $addMembers,
    ): RedirectResponse {
        $this->authorize('join', $project);

        $addMembers->handle(
            project: $project,
            actor: $request->user(),
            userIds: [$request->user()->id],
        );

        return back()->with('success', "You are on {$project->name}.");
    }

    public function destroy(
        Workspace $workspace,
        Project $project,
        Request $request,
        RemoveProjectMember $removeMember,
    ): RedirectResponse {
        $this->authorize('leave', $project);

        $user = $request->user();

        // The last lead leaving would strand the project — see
        // ProjectMemberController::update() for why that matters.
        $isLastLead = $project->membershipFor($user)?->isLead() === true
            && $project->members()->where('role', 'lead')->where('user_id', '!=', $user->id)->doesntExist();

        if ($isLastLead) {
            return back()->with('error', 'Make someone else a lead before you leave.');
        }

        $removeMember->handle($project, $user, $user);

        return to_route('projects.index', ['workspace' => $workspace->slug])
            ->with('success', "You left {$project->name}.");
    }
}
