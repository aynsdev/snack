<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Projects\MoveProjectGroup;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectGroupRequest;
use App\Http\Requests\Projects\UpdateProjectGroupRequest;
use App\Http\Resources\ProjectGroupResource;
use App\Models\ProjectGroup;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The headings the project list is arranged under — the app's side of
 * {@see \App\Http\Controllers\Projects\ProjectGroupController}, with the same
 * audit trail.
 *
 * Moving a project onto a heading is not here: it is a change to the project,
 * at `PATCH projects/{project}/group`.
 */
class ProjectGroupController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, StoreProjectGroupRequest $request): JsonResponse
    {
        $group = ProjectGroup::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'name' => $request->string('name')->trim()->toString(),
            'position' => ProjectGroup::nextPosition($workspace),
        ]);

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ProjectGroupCreated,
            actor: $request->user(),
            auditable: $group,
            newValues: ['name' => $group->name],
        );

        return (new ProjectGroupResource($group))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        UpdateProjectGroupRequest $request,
    ): ProjectGroupResource {
        $before = $projectGroup->name;

        $projectGroup->update([
            'name' => $request->string('name')->trim()->toString(),
        ]);

        if ($projectGroup->wasChanged('name')) {
            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ProjectGroupUpdated,
                actor: $request->user(),
                auditable: $projectGroup,
                oldValues: ['name' => $before],
                newValues: ['name' => $projectGroup->name],
            );
        }

        return new ProjectGroupResource($projectGroup);
    }

    /**
     * One place up or down. Answers with every heading in its new order, since
     * a move renumbers the neighbour too.
     */
    public function move(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        Request $request,
        MoveProjectGroup $moveGroup,
    ): AnonymousResourceCollection {
        $this->authorize('update', $projectGroup);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        $moveGroup->handle($projectGroup, up: $validated['direction'] === 'up');

        return ProjectGroupResource::collection($workspace->projectGroups()->ordered()->get());
    }

    /**
     * Removes the heading; its projects become ungrouped, not deleted.
     */
    public function destroy(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        Request $request,
    ): Response {
        $this->authorize('delete', $projectGroup);

        $name = $projectGroup->name;
        $ungrouped = $projectGroup->projects()->count();

        $projectGroup->delete();

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ProjectGroupDeleted,
            actor: $request->user(),
            auditable: $projectGroup,
            oldValues: ['name' => $name, 'projects' => $ungrouped],
        );

        return response()->noContent();
    }
}
