<?php

namespace App\Actions\Projects;

use App\Events\BoardChanged;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Outbound\AgentSettingsEvents;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Validation\ValidationException;

/**
 * Removes a column, moving whatever was in it somewhere else.
 *
 * The database would cascade the tasks away with the column — see the tasks
 * migration — and that is precisely what must not happen. Deleting a column is a
 * statement about the team's process, not about the work sitting in it, and
 * "our QA step went away and took eleven tickets with it" is unrecoverable.
 *
 * So the cards move first, to the column the caller names or to the one on its
 * left, and the delete only happens once the column is empty.
 *
 * A column the project's agents were using is cleared from the agent settings
 * by the database (`nullOnDelete`), and the agent dispatcher is told so — and
 * the audit log records it — the same as if a lead had cleared it by hand.
 */
class DeleteBoardColumn
{
    public function __construct(
        private readonly AgentSettingsEvents $agentSettings,
        private readonly OutboundEvents $events,
    ) {}

    /**
     * @throws ValidationException when the board would be left unusable
     */
    public function handle(BoardColumn $column, ?int $moveToColumnId = null, ?User $actor = null): void
    {
        $project = $column->project;
        $columns = $project->columns()->ordered()->get();

        if ($columns->count() <= 1) {
            throw ValidationException::withMessages([
                'column' => 'A board needs at least one column.',
            ]);
        }

        $sameCategory = $columns->firstWhere(
            fn (BoardColumn $each): bool => $each->id !== $column->id
                && $each->category === $column->category,
        );

        if ($sameCategory === null) {
            throw ValidationException::withMessages([
                'column' => "This is the board's only “{$column->category->label()}” column.",
            ]);
        }

        $destination = $columns->firstWhere('id', $moveToColumnId)
            ?? $columns->where('id', '!=', $column->id)->first();

        if ($destination === null || $destination->id === $column->id) {
            throw ValidationException::withMessages([
                'column' => 'Choose a column to move the remaining tasks to.',
            ]);
        }

        $this->events->transaction([$project->workspace_id], function () use ($project, $column, $destination, $actor): void {
            /*
             * The project row first, as everything that changes agent settings
             * or eligibility takes it — the column's `nullOnDelete` writes to
             * this row, and taking the lock up front is what keeps that write
             * from deadlocking against a task being filed into this column.
             * Then the column's cards in id order, before the bulk move takes
             * them in whatever order it meets them.
             */
            $locked = Project::query()->with('workspace')->lock('for no key update')->findOrFail($project->id);

            Task::query()->where('board_column_id', $column->id)->orderBy('id')->lockForUpdate()->pluck('id');

            $agentSettings = $locked->only(Project::AGENT_SETTINGS);
            $usedByAgents = in_array($column->id, [
                $locked->agent_in_progress_column_id,
                $locked->agent_review_column_id,
            ], true);

            if ($usedByAgents) {
                $this->agentSettings->changing($locked);
            }

            Task::query()
                ->where('board_column_id', $column->id)
                ->update([
                    'board_column_id' => $destination->id,
                    // The destination decides what "finished" means now: cards
                    // arriving in a Done column are done, and cards leaving one
                    // are not. Same rule as a drag — see MoveTask.
                    'completed_at' => $destination->isDone() ? now() : null,
                ]);

            $column->delete();

            if ($usedByAgents) {
                $this->agentSettings->auditClearing($locked->refresh(), $agentSettings, $actor);
            }
        });

        BoardChanged::dispatch($project);
    }
}
