<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The board: the active sprint's cards, in their columns.
 *
 * An Inertia page rather than a JSON endpoint the client assembles. A board is a
 * whole picture — columns, cards, who is on the project, what labels exist — and
 * every one of those is needed to draw the first frame. Fetching them after the
 * page painted would give a column of skeletons on every visit, and the state
 * that would justify it (a live, merging, paginated timeline) is not what a
 * board is.
 *
 * Live changes arrive over the socket instead and are applied to the list the
 * page already holds — see resources/js/hooks/use-board.ts.
 */
class BoardController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    public function show(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/board', [
            'project' => (new ProjectResource($project))->resolve($request),
            ...$this->boards->board($project, $request->user()),

            /*
             * A card opened straight from a link — the notification an
             * assignment raises points here, and so does a reference pasted into
             * a channel. Null when the URL names nothing, or names something
             * that is not on this board.
             */
            'openTask' => $this->openTask($project, $request),
        ]);
    }

    /**
     * The task named by `?task=PWA-1499`, if this project has one.
     *
     * Resolved by reference rather than by id, because the reference is the
     * thing people copy out of the board and paste to each other. Scoped to the
     * project the route already authorized, so a reference from another team's
     * board resolves to nothing here rather than to their card.
     *
     * @return array<string, mixed>|null
     */
    private function openTask(Project $project, Request $request): ?array
    {
        $reference = $request->string('task')->toString();

        if ($reference === '') {
            return null;
        }

        $number = (int) str($reference)->afterLast('-')->toString();

        if ($number <= 0) {
            return null;
        }

        $task = $project->tasks()
            ->where('number', $number)
            ->withCardData()
            ->first();

        return $task instanceof Task
            ? (new TaskResource($task))->resolve($request)
            : null;
    }
}
