<?php

namespace App\Http\Controllers\Projects;

use App\Enums\EligibilityReason;
use App\Events\BoardChanged;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\Workspace;
use App\Services\Outbound\TaskEligibilityEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The vocabulary a project hangs on its cards.
 *
 * Deleting a label detaches it from every card that wore it rather than
 * refusing while any do — the pivot cascades, and a label nobody can remove
 * because it is in use is a label that accumulates for ever.
 *
 * Renaming and deleting both change the label set of every card wearing the
 * label, so both are reported to the agent dispatcher card by card — see
 * {@see TaskEligibilityEvents}.
 */
class TaskLabelController extends Controller
{
    public function __construct(private readonly TaskEligibilityEvents $eligibility) {}

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

        $validated = $request->validate([
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
        ]);

        // A recolour changes nothing an agent cares about, so it locks nothing.
        if (! array_key_exists('name', $validated) || $validated['name'] === $label->name) {
            $label->update($validated);
        } else {
            $this->eligibility->track(
                $project,
                EligibilityReason::LabelRenamed,
                fn (): Builder => $this->tasksWearing($label),
                fn (): bool => $label->update($validated),
            );
        }

        BoardChanged::dispatch($project);

        return back()->with('success', 'Label updated.');
    }

    public function destroy(
        Workspace $workspace,
        Project $project,
        TaskLabel $label,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $this->eligibility->track(
            $project,
            EligibilityReason::LabelDeleted,
            fn (): Builder => $this->tasksWearing($label),
            fn (): ?bool => $label->delete(),
        );

        BoardChanged::dispatch($project);

        return back()->with('success', 'Label removed.');
    }

    /**
     * The cards wearing a label, selected only once the label row is locked —
     * after the project, per {@see TaskEligibilityEvents} — so no card can
     * put the label on between being missed here and the label changing.
     *
     * @return Builder<Task>
     */
    private function tasksWearing(TaskLabel $label): Builder
    {
        TaskLabel::query()->whereKey($label->id)->lockForUpdate()->first();

        return Task::query()->whereHas('labels', fn (Builder $labels) => $labels->whereKey($label->id));
    }
}
