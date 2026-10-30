<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteTask;
use App\Actions\Projects\ResolveTaskAgentIntent;
use App\Actions\Projects\SaveTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreTaskRequest;
use App\Http\Requests\Projects\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Filing, editing, and deleting cards.
 *
 * These answer with JSON rather than Inertia redirects, which is the opposite of
 * how the rest of Snack's forms work and is deliberate: the board is a live list
 * the client already holds, drawn from a socket as well as from the page's
 * props. A redirect would re-render the entire board to add one card — throwing
 * away the scroll position and any panel the author had open — for a change the
 * client can apply in place.
 *
 * The saved card comes back in full so the client stores the server's version
 * rather than its own optimistic guess; see {@see App\Events\TaskChanged} for
 * why "the whole card, every time" is the rule on both paths.
 */
class TaskController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        StoreTaskRequest $request,
        SaveTask $saveTask,
    ): JsonResponse {
        $task = $saveTask->create(
            project: $project,
            reporter: $request->user(),
            attributes: $request->taskAttributes(),
        );

        return response()->json([
            'task' => (new TaskResource($task->load(['project', 'assignee', 'labels', 'epic', 'release'])))->resolve($request),
        ], 201);
    }

    /**
     * One card in full.
     *
     * The only place a card's discussion and files are sent, which is why the
     * panel asks for this on open rather than drawing everything from the board
     * it was clicked on: a board is eighty cards, and eighty cards' worth of
     * comments is a payload nobody reads.
     */
    public function show(Workspace $workspace, Task $task, Request $request): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'task' => (new TaskResource($task->load([
                'project', 'assignee', 'labels', 'epic', 'release', 'reporter',
                'comments.author', 'attachments', 'links',
                'children.column', 'children.assignee',
            ])))->resolve($request),
        ]);
    }

    public function update(
        Workspace $workspace,
        Task $task,
        UpdateTaskRequest $request,
        SaveTask $saveTask,
        ResolveTaskAgentIntent $agentIntent,
    ): JsonResponse {
        $attributes = $request->taskAttributes();
        $intent = $request->agentIntent();

        if ($intent !== null) {
            $attributes = [
                ...$attributes,
                ...$agentIntent->handle($task, $request->user(), $intent, $request->integer('assignee_id') ?: null),
            ];
        }

        $saved = $saveTask->update(
            task: $task,
            actor: $request->user(),
            attributes: $attributes,
        );

        return response()->json([
            'task' => (new TaskResource(
                $saved->load(['project', 'assignee', 'labels', 'epic', 'release']),
            ))->resolve($request),
        ]);
    }

    public function destroy(
        Workspace $workspace,
        Task $task,
        Request $request,
        DeleteTask $deleteTask,
    ): JsonResponse {
        $this->authorize('delete', $task);

        $deleteTask->handle($task);

        return response()->json(['deleted' => true]);
    }
}
