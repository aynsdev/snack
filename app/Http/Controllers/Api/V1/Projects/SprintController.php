<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\CompleteSprint;
use App\Actions\Projects\SaveSprint;
use App\Actions\Projects\StartSprint;
use App\Events\BoardChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\SprintResource;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Planning sprints and running them — see
 * {@see \App\Http\Controllers\Projects\SprintController}.
 *
 * Starting or completing one reshapes the board, so after either the app
 * should re-read `GET projects/{project}` rather than patch what it holds.
 */
class SprintController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        SaveSprint $saveSprint,
    ): JsonResponse {
        $this->authorize('manageSprints', $project);

        $sprint = $saveSprint->create($project, $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'goal' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]));

        BoardChanged::dispatch($project);

        return (new SprintResource($sprint))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        SaveSprint $saveSprint,
    ): SprintResource {
        $this->authorize('manageSprints', $project);

        return new SprintResource($saveSprint->update($sprint, $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ])));
    }

    /**
     * Starts the sprint; 422 if another is already running ({@see StartSprint}).
     */
    public function start(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        StartSprint $startSprint,
    ): SprintResource {
        $this->authorize('manageSprints', $project);

        return new SprintResource($startSprint->handle($sprint, $request->user()));
    }

    /**
     * Closes the sprint, carrying unfinished work to `move_to_sprint_id` or the
     * backlog, and says how much went each way.
     */
    public function complete(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        CompleteSprint $completeSprint,
    ): JsonResponse {
        $this->authorize('manageSprints', $project);

        $validated = $request->validate([
            'move_to_sprint_id' => ['nullable', 'integer'],
        ]);

        $result = $completeSprint->handle($sprint, $request->user(), $validated['move_to_sprint_id'] ?? null);

        return response()->json([
            'data' => (new SprintResource($sprint->refresh()))->resolve($request),
            'meta' => ['completed' => $result['completed'], 'moved' => $result['moved']],
        ]);
    }

    /**
     * Deletes a planned sprint, returning its work to the backlog. A sprint that
     * has started is a record, not a plan, and is refused.
     */
    public function destroy(Workspace $workspace, Project $project, Sprint $sprint): Response
    {
        $this->authorize('manageSprints', $project);

        if ($sprint->started_at !== null) {
            throw ValidationException::withMessages([
                'sprint' => 'A sprint that has started cannot be deleted. Complete it instead.',
            ]);
        }

        $sprint->tasks()->update(['sprint_id' => null]);
        $sprint->delete();

        BoardChanged::dispatch($project);

        return response()->noContent();
    }
}
