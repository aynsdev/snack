<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Starring a project — a fact about the viewer, so it answers with nothing.
 * See {@see \App\Http\Controllers\Projects\ProjectStarController}.
 */
class ProjectStarController extends Controller
{
    public function store(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('star', $project);

        // Idempotent: a double-tapped star must not move the date the starred
        // list is ordered by.
        $membership = $project->membershipFor($request->user());

        if ($membership !== null && $membership->starred_at === null) {
            $membership->forceFill(['starred_at' => now()])->save();
        }

        return response()->noContent();
    }

    public function destroy(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('star', $project);

        $project->membershipFor($request->user())
            ?->forceFill(['starred_at' => null])
            ->save();

        return response()->noContent();
    }
}
