<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Projects\ProjectSummaryController as WebProjectSummaryController;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The project at a glance, as JSON.
 *
 * Extends the web controller rather than repeating it: every panel is an
 * aggregate query that lives there, and two copies of "what counts as due soon"
 * would drift. Only the response differs.
 */
class ProjectSummaryController extends WebProjectSummaryController
{
    public function apiShow(Workspace $workspace, Project $project, Request $request, ProjectBoard $boards): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project))->resolve($request),
                'members' => $boards->members($project),
                'recent' => $this->recentCounts($project),
                'statuses' => $this->byStatus($project),
                'priorities' => $this->byPriority($project),
                'types' => $this->byType($project),
                'activity' => $this->activity($project),
                'can_manage' => $request->user()->can('update', $project),
            ],
        ]);
    }
}
