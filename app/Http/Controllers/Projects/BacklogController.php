<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The backlog: the sprints being planned, and the uncommitted work under them.
 *
 * # Why a kanban project has none
 *
 * Without sprints there is nothing to plan *into*: everything the project has is
 * already on the board, and a backlog page would be the same list at a second
 * address. So the route redirects there instead of rendering an empty page that
 * looks broken.
 */
class BacklogController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    public function index(
        Workspace $workspace,
        Project $project,
        Request $request,
    ): Response|RedirectResponse {
        $this->authorize('view', $project);

        if (! $project->uses_sprints) {
            return to_route('projects.board', [
                'workspace' => $workspace->slug,
                'project' => $project->slug,
            ]);
        }

        return Inertia::render('projects/backlog', [
            'project' => (new ProjectResource($project))->resolve($request),
            ...$this->boards->backlog($project, $request->user()),
        ]);
    }
}
