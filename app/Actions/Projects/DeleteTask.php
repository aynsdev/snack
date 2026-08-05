<?php

namespace App\Actions\Projects;

use App\Events\TaskChanged;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

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
    public function handle(Task $task): void
    {
        $project = $task->project;
        $id = $task->id;
        $reference = $task->reference();

        DB::transaction(function () use ($task): void {
            $task->children()->update(['parent_task_id' => null]);
            $task->labels()->detach();
            $task->delete();
        });

        event(TaskChanged::deleted($project, $id, $reference));
    }
}
