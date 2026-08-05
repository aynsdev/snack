<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Events\BoardChanged;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a sprint and decides what happens to the work that did not get done.
 *
 * # Unfinished work moves, it does not close
 *
 * Everything still outside a Done column is moved into the next sprint, or back
 * to the backlog when there is no next one — *keeping the column it reached*.
 * That is the whole reason a task's status and its sprint are separate columns:
 * a card that got as far as "To test" and ran out of fortnight is still in "To
 * test" on Monday, and a model that stored only "which list is it in" has
 * already thrown that away.
 *
 * Nothing is deleted and nothing is marked complete that was not. A sprint
 * report that closed its own leftovers would be a report of a sprint that never
 * happened.
 */
class CompleteSprint
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  int|null  $moveToSprintId  where the leftovers go; null is the backlog
     * @return array{completed: int, moved: int}
     *
     * @throws ValidationException when the sprint is not running
     */
    public function handle(Sprint $sprint, User $actor, ?int $moveToSprintId = null): array
    {
        if (! $sprint->isActive()) {
            throw ValidationException::withMessages([
                'sprint' => 'Only a running sprint can be completed.',
            ]);
        }

        $project = $sprint->project;

        // Re-resolved against this project, and never the sprint being closed:
        // the destination arrives from a select in the browser.
        $destination = $moveToSprintId === null
            ? null
            : $project->sprints()->open()->whereKeyNot($sprint->id)->whereKey($moveToSprintId)->first();

        $result = DB::transaction(function () use ($sprint, $project, $destination): array {
            $doneColumnIds = $project->columns()->where('category', 'done')->pluck('id')->all();

            $completed = Task::query()
                ->where('sprint_id', $sprint->id)
                ->whereIn('board_column_id', $doneColumnIds)
                ->count();

            $moved = Task::query()
                ->where('sprint_id', $sprint->id)
                ->whereNotIn('board_column_id', $doneColumnIds)
                ->update(['sprint_id' => $destination?->id]);

            $sprint->forceFill(['completed_at' => now()])->save();

            return ['completed' => $completed, 'moved' => $moved];
        });

        $this->audit->handle(
            workspace: $project->workspace,
            action: AuditAction::SprintCompleted,
            actor: $actor,
            auditable: $project,
            newValues: [
                'sprint' => $sprint->name,
                'sprint_id' => $sprint->id,
                'completed_tasks' => $result['completed'],
                'carried_over' => $result['moved'],
                'carried_to' => $destination?->name,
            ],
        );

        BoardChanged::dispatch($project);

        return $result;
    }
}
