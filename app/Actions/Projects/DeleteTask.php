<?php

namespace App\Actions\Projects;

use App\Enums\EligibilityReason;
use App\Enums\TaskType;
use App\Events\TaskChanged;
use App\Models\Task;
use App\Services\Outbound\TaskEligibilityEvents;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes a task.
 *
 * An epic's children are freed rather than deleted with it — the database says
 * the same thing with `nullOnDelete`, and this is where it is stated in words:
 * an epic is a way of grouping work, so removing the grouping must not remove
 * the work.
 *
 * The event is dispatched with the id captured beforehand, because by the time
 * anyone listening reacts there is no row left to read it from.
 */
class DeleteTask
{
    public function __construct(
        private readonly AnnounceParentProgress $parents,
        private readonly TaskEligibilityEvents $eligibility,
    ) {}

    public function handle(Task $task): void
    {
        $project = $task->project;
        $id = $task->id;
        $reference = $task->reference();
        $parentId = $task->parent_task_id;

        $this->eligibility->track($project, EligibilityReason::Deleted, fn (): Builder => Task::query()->whereKey($id), function () use ($task): void {
            // A subtask is always a piece of something, so one whose parent
            // is gone becomes an ordinary task rather than a dangling piece.
            $task->children()->where('type', TaskType::Subtask)->update(['type' => TaskType::Task]);
            $task->children()->update(['parent_task_id' => null]);
            $task->labels()->detach();
            $task->delete();
        });

        event(TaskChanged::deleted($project, $id, $reference));
        $this->parents->announce([$parentId]);
    }
}
