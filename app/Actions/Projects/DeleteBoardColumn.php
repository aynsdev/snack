<?php

namespace App\Actions\Projects;

use App\Events\BoardChanged;
use App\Models\BoardColumn;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
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
 */
class DeleteBoardColumn
{
    /**
     * @throws ValidationException when the board would be left unusable
     */
    public function handle(BoardColumn $column, ?int $moveToColumnId = null): void
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

        DB::transaction(function () use ($column, $destination): void {
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
        });

        BoardChanged::dispatch($project);
    }
}
