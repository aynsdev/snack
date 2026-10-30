<?php

namespace App\Actions\Projects;

use App\Enums\TaskType;
use App\Events\TaskChanged;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Support\Positions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drops a card somewhere else: another column, another sprint, another place in
 * the same list.
 *
 * Separate from {@see SaveTask} because it is a different kind of write. An edit
 * is a form somebody filled in; this fires on a drag, several times a minute,
 * from several people at once, and each one has to touch as little as possible.
 *
 * # What gets written
 *
 * One row — the card that moved. Its new position is the midpoint between the
 * two cards it was dropped between, so nothing else in the column changes and
 * two people rearranging different parts of the same board never overwrite each
 * other. See {@see Positions} for why the obvious "renumber the list" is the
 * thing that loses moves.
 *
 * The exception is when a seam has been used so many times there is no integer
 * left in it. Then, and only then, that one list is spread out again and the
 * move is retried — bounded work, roughly once every few thousand drags.
 */
class MoveTask
{
    public function __construct(private readonly AnnounceParentProgress $parents) {}

    /**
     * @param  array{
     *     board_column_id?: int|null,
     *     sprint_id?: int|null,
     *     in_backlog?: bool,
     *     before_task_id?: int|null,
     *     after_task_id?: int|null,
     *  }  $target
     */
    public function handle(Task $task, array $target): Task
    {
        $project = $task->project;
        $previousSprintId = $task->sprint_id;
        $wasComplete = $task->isComplete();

        DB::transaction(function () use ($project, $task, $target): void {
            $column = $this->column($project, $target['board_column_id'] ?? null) ?? $task->column;
            $sprintId = $this->sprintId($project, $task, $target);

            $task->forceFill([
                'board_column_id' => $column->id,
                'sprint_id' => $sprintId,
                'position' => $this->position($project, $task, $column->id, $sprintId, $target),

                /*
                 * Completion follows the column, in both directions.
                 *
                 * Dragging a card out of Done because it was closed too early
                 * has to clear the date as well — otherwise the card reads as
                 * unfinished on the board and as finished in every count, and
                 * the sprint report is the thing that ends up lying.
                 *
                 * A card moved between two Done columns keeps its original
                 * date: it was finished when it was finished.
                 */
                'completed_at' => match (true) {
                    $column->isDone() => $task->completed_at ?? now(),
                    default => null,
                },
            ])->save();
        });

        event(TaskChanged::for($task->refresh(), 'moved'));

        if ($task->sprint_id !== $previousSprintId) {
            $this->carrySubtasks($task);
        }

        if ($task->isComplete() !== $wasComplete) {
            $this->parents->announce([$task->parent_task_id]);
        }

        return $task;
    }

    /**
     * Subtasks go where their parent goes, as in Jira: committing a story to
     * a sprint commits its pieces, and sending it back to the backlog takes
     * them too. Each keeps its own status and is appended to the bottom of
     * its column in the new sprint. Finished subtasks stay where they were
     * done — a closed sprint's record of them must not change.
     *
     * Only subtasks. A story under another story is its own piece of work,
     * planned on its own.
     */
    private function carrySubtasks(Task $parent): void
    {
        $parent->children()
            ->where('type', TaskType::Subtask)
            ->whereNull('completed_at')
            ->where(fn ($query) => $parent->sprint_id === null
                ? $query->whereNotNull('sprint_id')
                : $query->whereNull('sprint_id')->orWhere('sprint_id', '!=', $parent->sprint_id))
            ->get()
            ->each(function (Task $subtask) use ($parent): void {
                $subtask->setRelation('project', $parent->project);

                $this->handle($subtask, $parent->sprint_id === null
                    ? ['in_backlog' => true]
                    : ['sprint_id' => $parent->sprint_id]);
            });
    }

    private function column(Project $project, ?int $columnId): ?BoardColumn
    {
        return $columnId === null
            ? null
            : $project->columns()->whereKey($columnId)->first();
    }

    /**
     * Which sprint the card lands in — or the backlog.
     *
     * `in_backlog` is an explicit flag rather than a null `sprint_id`, because
     * "drop this into the backlog" and "the request said nothing about sprints"
     * are different instructions and a null cannot tell them apart. Dragging
     * between columns on the board must not silently pull a card out of the
     * sprint it is committed to.
     *
     * @param  array<string, mixed>  $target
     */
    private function sprintId(Project $project, Task $task, array $target): ?int
    {
        if (($target['in_backlog'] ?? false) === true) {
            return null;
        }

        if (! array_key_exists('sprint_id', $target) || $target['sprint_id'] === null) {
            return $task->sprint_id;
        }

        $sprint = $project->sprints()->open()->whereKey((int) $target['sprint_id'])->first();

        return $sprint instanceof Sprint ? $sprint->id : $task->sprint_id;
    }

    /**
     * The midpoint between the card's new neighbours, rebalancing the
     * destination list first if the two of them have closed up.
     *
     * @param  array<string, mixed>  $target
     */
    private function position(
        Project $project,
        Task $task,
        int $columnId,
        ?int $sprintId,
        array $target,
    ): int {
        [$before, $after] = $this->neighbours($project, $task, $target);

        if (! Positions::needsRebalance($before, $after)) {
            return Positions::between($before, $after);
        }

        $this->rebalance($project, $columnId, $sprintId, $task);

        [$before, $after] = $this->neighbours($project, $task, $target);

        return Positions::between($before, $after);
    }

    /**
     * The positions of the cards either side of the drop, read back from the
     * database rather than taken from the request.
     *
     * The client sends ids; the positions those ids currently hold are the
     * server's business. A client that sent positions would be sending a copy of
     * the board it painted some seconds ago, and every stale copy is a card that
     * lands in the wrong place.
     *
     * @param  array<string, mixed>  $target
     * @return array{0: int|null, 1: int|null}
     */
    private function neighbours(Project $project, Task $task, array $target): array
    {
        $ids = array_values(array_filter([
            $target['before_task_id'] ?? null,
            $target['after_task_id'] ?? null,
        ]));

        /** @var Collection<int, Task> $neighbours */
        $neighbours = $ids === []
            ? collect()
            : $project->tasks()->whereIn('id', $ids)->whereKeyNot($task->id)->get()->keyBy('id');

        $before = isset($target['before_task_id'])
            ? $neighbours->get((int) $target['before_task_id'])?->position
            : null;

        $after = isset($target['after_task_id'])
            ? $neighbours->get((int) $target['after_task_id'])?->position
            : null;

        return [$before, $after];
    }

    /**
     * Spreads one list back out, leaving the moving card out of it.
     *
     * Scoped to the destination column and sprint, which is the only list whose
     * gaps ran out — a board-wide renumber would be exactly the lost-update
     * problem this design exists to avoid, just rarer.
     */
    private function rebalance(Project $project, int $columnId, ?int $sprintId, Task $moving): void
    {
        $this->lockList($project, $columnId, $sprintId);

        $tasks = $project->tasks()
            ->where('board_column_id', $columnId)
            ->where(fn ($query) => $sprintId === null
                ? $query->whereNull('sprint_id')
                : $query->where('sprint_id', $sprintId))
            ->whereKeyNot($moving->id)
            ->ordered()
            ->get();

        $positions = Positions::spread($tasks);

        foreach ($tasks as $index => $task) {
            $task->forceFill(['position' => $positions[$index]])->save();
        }
    }

    /**
     * The project row and then the list's cards in id order, before any of
     * them is written: the order every change that reports to the agent
     * dispatcher locks in (see App\Services\Outbound\OutboundEvents), so a
     * rebalance and one of those cannot each hold what the other waits for.
     */
    private function lockList(Project $project, int $columnId, ?int $sprintId): void
    {
        Project::query()->whereKey($project->id)->lock('for no key update')->first();

        $project->tasks()
            ->where('board_column_id', $columnId)
            ->where(fn ($query) => $sprintId === null
                ? $query->whereNull('sprint_id')
                : $query->where('sprint_id', $sprintId))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }
}
