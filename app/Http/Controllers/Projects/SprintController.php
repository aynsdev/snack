<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CompleteSprint;
use App\Actions\Projects\SaveSprint;
use App\Actions\Projects\StartSprint;
use App\Events\BoardChanged;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Planning sprints, and running them.
 *
 * Inertia redirects rather than JSON, unlike the task endpoints: every one of
 * these changes the page as a whole. Starting a sprint replaces the board, and
 * completing one empties it — there is no in-place update for the client to
 * apply, so a re-render is exactly what is wanted.
 */
class SprintController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        SaveSprint $saveSprint,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $attributes = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'goal' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $sprint = $saveSprint->create($project, $attributes);

        BoardChanged::dispatch($project);

        return back()->with('success', "{$sprint->name} planned.");
    }

    public function update(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        SaveSprint $saveSprint,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $saveSprint->update($sprint, $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ]));

        return back()->with('success', 'Sprint updated.');
    }

    /**
     * Starts the sprint. Refuses if one is already running — see
     * {@see StartSprint}, which raises that as a validation error so it lands on
     * the form rather than as a 500.
     */
    public function start(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        StartSprint $startSprint,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $startSprint->handle($sprint, $request->user());

        return to_route('projects.board', [
            'workspace' => $workspace->slug,
            'project' => $project->slug,
        ])->with('success', "{$sprint->name} started.");
    }

    /**
     * Closes the sprint, moving whatever is unfinished to the sprint named in
     * `move_to_sprint_id` or back to the backlog.
     */
    public function complete(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        Request $request,
        CompleteSprint $completeSprint,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $validated = $request->validate([
            'move_to_sprint_id' => ['nullable', 'integer'],
        ]);

        $result = $completeSprint->handle(
            $sprint,
            $request->user(),
            $validated['move_to_sprint_id'] ?? null,
        );

        return back()->with('success', sprintf(
            '%s completed — %d done, %d carried over.',
            $sprint->name,
            $result['completed'],
            $result['moved'],
        ));
    }

    /**
     * Deletes a planned sprint, returning its contents to the backlog.
     *
     * A running or completed sprint is refused: the first is what the team is
     * doing right now, and the second is a record of what they did.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        if ($sprint->started_at !== null) {
            throw ValidationException::withMessages([
                'sprint' => 'A sprint that has started cannot be deleted. Complete it instead.',
            ]);
        }

        // Explicit rather than relying on the foreign key's nullOnDelete, so the
        // intent is visible here: the work goes back to the backlog, it does not
        // go away with the plan.
        $sprint->tasks()->update(['sprint_id' => null]);
        $sprint->delete();

        BoardChanged::dispatch($project);

        return back()->with('success', 'Sprint deleted.');
    }
}
