<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\MoveTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\MoveTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * A card was dropped somewhere.
 *
 * Its own endpoint rather than a field on the edit route, because it is a
 * different kind of request: it fires on a drag rather than on a form, several
 * times a minute, and it is the one write on this board that is throttled as
 * traffic. Sharing a route would also mean a stray `board_column_id` in an edit
 * payload could move a card nobody dragged.
 */
class TaskMoveController extends Controller
{
    public function update(
        Workspace $workspace,
        Task $task,
        MoveTaskRequest $request,
        MoveTask $moveTask,
    ): JsonResponse {
        $moved = $moveTask->handle($task, $request->target());

        return response()->json([
            'task' => (new TaskResource(
                $moved->load(['project', 'assignee', 'labels', 'epic']),
            ))->resolve($request),
        ]);
    }
}
