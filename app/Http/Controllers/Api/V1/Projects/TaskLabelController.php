<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Events\BoardChanged;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TaskLabel;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * A project's labels, as on the web ({@see \App\Http\Controllers\Projects\TaskLabelController}).
 *
 * Answers in the `{id, name, color}` shape the board payload lists labels in,
 * and announces every change on the board's channel the way the web does.
 */
class TaskLabelController extends Controller
{
    public function store(Workspace $workspace, Project $project, Request $request): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('task_labels', 'name')->where('project_id', $project->id),
            ],
            'color' => ['required', 'string', 'max:20'],
        ]);

        $label = $project->labels()->create($validated);

        BoardChanged::dispatch($project);

        return response()->json(['data' => $this->payload($label)], 201);
    }

    public function update(Workspace $workspace, Project $project, TaskLabel $label, Request $request): JsonResponse
    {
        $this->authorize('update', $project);

        $label->update($request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('task_labels', 'name')
                    ->where('project_id', $project->id)
                    ->ignore($label->id),
            ],
            'color' => ['sometimes', 'string', 'max:20'],
        ]));

        BoardChanged::dispatch($project);

        return response()->json(['data' => $this->payload($label)]);
    }

    /**
     * Detaches the label from every card that wore it.
     */
    public function destroy(Workspace $workspace, Project $project, TaskLabel $label): Response
    {
        $this->authorize('update', $project);

        $label->delete();

        BoardChanged::dispatch($project);

        return response()->noContent();
    }

    /**
     * @return array{id: int, name: string, color: string}
     */
    private function payload(TaskLabel $label): array
    {
        return ['id' => $label->id, 'name' => $label->name, 'color' => $label->color];
    }
}
