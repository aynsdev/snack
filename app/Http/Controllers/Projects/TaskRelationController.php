<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\LinkTask;
use App\Enums\TaskRelationType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskRelation;
use App\Models\Workspace;
use App\Support\TaskRelations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One card's links to other cards: blocks, relates to, duplicates.
 *
 * Said from the card being edited, so it needs the right to change that card
 * and only to see the other one. Removing works from either card's panel,
 * since the relation shows on both.
 */
class TaskRelationController extends Controller
{
    public function store(Workspace $workspace, Task $task, Request $request, LinkTask $linkTask): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'related_task_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(TaskRelationType::class)],
        ]);

        $other = Task::query()->with('project')->whereKey((int) $validated['related_task_id'])->first();

        // A 404 rather than a 422 for a card the person cannot open, so this
        // cannot be used to confirm that a task id exists.
        abort_if($other === null || $request->user()->cannot('view', $other), 404);

        $linkTask->relate($task, $other, TaskRelationType::from($validated['type']), $request->user());

        return response()->json(['relations' => TaskRelations::payload($task, $request->user())], 201);
    }

    public function destroy(Workspace $workspace, Task $task, TaskRelation $relation, Request $request): JsonResponse
    {
        // Unscoped route (see routes/web.php), so the workspace is checked here.
        abort_unless($task->project->workspace_id === $workspace->id, 404);
        abort_unless($relation->task_id === $task->id || $relation->related_task_id === $task->id, 404);

        $this->authorize('update', $task);

        $relation->delete();

        return response()->json(['relations' => TaskRelations::payload($task, $request->user())]);
    }
}
