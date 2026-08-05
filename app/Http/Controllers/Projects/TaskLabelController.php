<?php

namespace App\Http\Controllers\Projects;

use App\Events\BoardChanged;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TaskLabel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The vocabulary a project hangs on its cards.
 *
 * Deleting a label detaches it from every card that wore it rather than
 * refusing while any do — the pivot cascades, and a label nobody can remove
 * because it is in use is a label that accumulates for ever.
 */
class TaskLabelController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
    ): RedirectResponse {
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

        $project->labels()->create($validated);

        BoardChanged::dispatch($project);

        return back()->with('success', 'Label added.');
    }

    public function update(
        Workspace $workspace,
        Project $project,
        TaskLabel $label,
        Request $request,
    ): RedirectResponse {
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

        return back()->with('success', 'Label updated.');
    }

    public function destroy(
        Workspace $workspace,
        Project $project,
        TaskLabel $label,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $label->delete();

        BoardChanged::dispatch($project);

        return back()->with('success', 'Label removed.');
    }
}
