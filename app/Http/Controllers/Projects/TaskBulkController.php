<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\BulkUpdateTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\BulkUpdateTasksRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Several selected cards, changed at once from the board or the backlog.
 *
 * Returns every selected card as it now is, so the client replaces its
 * optimistic guess with the truth in one go rather than waiting for one socket
 * broadcast per card.
 */
class TaskBulkController extends Controller
{
    public function update(
        Workspace $workspace,
        Project $project,
        BulkUpdateTasksRequest $request,
        BulkUpdateTasks $bulkUpdate,
    ): JsonResponse {
        $result = $bulkUpdate->handle(
            project: $project,
            actor: $request->user(),
            taskIds: $request->taskIds(),
            placement: $request->placement(),
            agent: $request->agent(),
        );

        return response()->json([
            'tasks' => $result['tasks']
                ->map(fn (Task $task): array => (new TaskResource(
                    $task->load(['project', 'assignee', 'labels', 'epic', 'release']),
                ))->resolve($request))
                ->all(),
            'skipped' => $result['skipped']->map(fn (Task $task): string => $task->reference())->all(),
        ]);
    }
}
