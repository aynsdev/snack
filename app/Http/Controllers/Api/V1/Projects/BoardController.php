<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The board and the backlog, as the web pages draw them.
 *
 * Both come from {@see ProjectBoard}, the same service the web pages and the
 * socket use, so the app's first frame and every later live update agree. The
 * service's camelCase prop names are snake-cased on the way out to match the
 * rest of the API.
 */
class BoardController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    /**
     * The active sprint's cards in their columns, plus everything needed to draw
     * them. `?task=PWA-1499` also returns that card in full as `open_task` — the
     * way a notification deep-links into a board.
     */
    public function show(Workspace $workspace, Project $project, Request $request): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project))->resolve($request),
                ...$this->snakeKeys($this->boards->board($project, $request->user())),
                'open_task' => $this->openTask($project, $request),
            ],
        ]);
    }

    /**
     * The open sprints with their contents, and the uncommitted work under them.
     *
     * A kanban project has no backlog — its work is all on the board — so this
     * answers 404 there, where the web redirects to the board.
     */
    public function backlog(Workspace $workspace, Project $project, Request $request): JsonResponse
    {
        $this->authorize('view', $project);

        abort_unless($project->uses_sprints, 404, 'This project does not use sprints, so it has no backlog.');

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project))->resolve($request),
                ...$this->snakeKeys($this->boards->backlog($project, $request->user())),
            ],
        ]);
    }

    /**
     * The card named by `?task=`, resolved by its reference within this project
     * only — see the web BoardController for why by reference.
     *
     * @return array<string, mixed>|null
     */
    private function openTask(Project $project, Request $request): ?array
    {
        $number = (int) Str::afterLast($request->string('task')->toString(), '-');

        if ($number <= 0) {
            return null;
        }

        $task = $project->tasks()->where('number', $number)->withCardData()->first();

        return $task instanceof Task ? (new TaskResource($task))->resolve($request) : null;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function snakeKeys(array $props): array
    {
        return collect($props)->mapWithKeys(fn (mixed $value, string $key): array => [Str::snake($key) => $value])->all();
    }
}
