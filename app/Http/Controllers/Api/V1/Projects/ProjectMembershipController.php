<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\AddProjectMembers;
use App\Actions\Projects\RemoveProjectMember;
use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Joining and leaving a project yourself — see
 * {@see \App\Http\Controllers\Projects\ProjectMembershipController} for why this
 * is a different right from adding somebody else.
 */
class ProjectMembershipController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        AddProjectMembers $addMembers,
    ): JsonResponse {
        $this->authorize('join', $project);

        $addMembers->handle(
            project: $project,
            actor: $request->user(),
            userIds: [$request->user()->id],
        );

        $project->unsetRelation('members');

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    /**
     * Leaving. The last lead is refused — a project nobody can manage is
     * stranded.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        Request $request,
        RemoveProjectMember $removeMember,
    ): Response {
        $this->authorize('leave', $project);

        $user = $request->user();

        $isLastLead = $project->membershipFor($user)?->isLead() === true
            && $project->members()
                ->where('role', ProjectRole::Lead->value)
                ->where('user_id', '!=', $user->id)
                ->doesntExist();

        if ($isLastLead) {
            throw ValidationException::withMessages([
                'membership' => 'Make someone else a lead before you leave.',
            ]);
        }

        $removeMember->handle($project, $user, $user);

        return response()->noContent();
    }
}
