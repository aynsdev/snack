<?php

namespace App\Mcp\Tools\Concerns;

use App\Actions\Projects\SaveTask;
use App\Ai\BiteContext;
use App\Enums\TaskType;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskLabel;

/**
 * Turning where an assistant says a task goes — "Done", "the active sprint",
 * "v3.2.1", "under ENG-12", "bug, frontend" — into the ids SaveTask and
 * MoveTask take.
 *
 * Shared by create_task, update_task and update_tasks so the three agree on
 * what every word means. Each name is resolved within the task's own project,
 * and a name that matches nothing is refused with the valid options rather
 * than dropped: SaveTask quietly nulls an id it does not recognise, which is
 * right for a stale picker on screen and wrong for an assistant, which would
 * then report a change that never happened.
 *
 * Expects {@see ResolvesProjectWork} alongside it.
 */
trait PlacesTasks
{
    /**
     * Resolve every placement field present in `$arguments`.
     *
     * Returns the error to show when anything fails to resolve — nothing is
     * half-applied — or:
     *  - `save`: attributes for {@see SaveTask} (release_id, parent_task_id, label_ids)
     *  - `move`: a target for MoveTask (board_column_id, sprint_id, in_backlog)
     *  - `changed`: what is changing, in words, for the reply
     *
     * @param  array<string, mixed>  $arguments
     * @return array{save: array<string, mixed>, move: array<string, mixed>, changed: list<string>}|string
     */
    protected function placement(BiteContext $context, Project $project, array $arguments, ?Task $task = null): array|string
    {
        $save = [];
        $move = [];
        $changed = [];

        if (array_key_exists('status', $arguments)) {
            $column = $this->column($project, $arguments['status']);

            if (! $column instanceof BoardColumn) {
                return $column;
            }

            $move['board_column_id'] = $column->id;
            $changed[] = "status → {$column->name}";
        }

        if (array_key_exists('sprint', $arguments)) {
            $sprint = $this->sprintNamed($project, $arguments['sprint']);

            if (is_string($sprint)) {
                return $sprint;
            }

            if ($sprint === false) {
                $move['in_backlog'] = true;
                $changed[] = 'sprint → backlog';
            } else {
                $move['sprint_id'] = $sprint->id;
                $changed[] = "sprint → {$sprint->name}";
            }
        }

        if (array_key_exists('release', $arguments)) {
            $release = $this->releaseNamed($project, $arguments['release']);

            if (is_string($release)) {
                return $release;
            }

            $save['release_id'] = $release?->id;
            $changed[] = 'release → '.($release->name ?? 'none');
        }

        $type = TaskType::tryFrom((string) ($arguments['type'] ?? '')) ?? $task->type ?? TaskType::Task;

        if ($type->isSubtask() && ! array_key_exists('parent', $arguments) && $task?->parent_task_id === null) {
            return 'A subtask always has a parent: pass `parent` with the reference of a story, task, or bug. Nothing was changed.';
        }

        if ($type->isSubtask() && $task !== null && $task->children()->exists()) {
            return "{$task->reference()} has subtasks of its own, so it cannot become a subtask. Nothing was changed.";
        }

        if (array_key_exists('parent', $arguments)) {
            $parent = $this->parentNamed($context, $project, $arguments['parent'], $type, $task);

            if (is_string($parent)) {
                return $parent;
            }

            $save['parent_task_id'] = $parent?->id;
            $changed[] = 'parent → '.($parent !== null ? $project->reference($parent->number) : 'none');
        }

        $labelArguments = array_intersect_key($arguments, array_flip(['labels', 'add_labels', 'remove_labels']));

        if ($labelArguments !== []) {
            $current = $task?->labels()->pluck('task_labels.id')->all() ?? [];
            $ids = array_key_exists('labels', $labelArguments) ? [] : $current;

            foreach (['labels' => true, 'add_labels' => true, 'remove_labels' => false] as $key => $adding) {
                if (! array_key_exists($key, $labelArguments)) {
                    continue;
                }

                $labels = $this->labelsNamed($project, $labelArguments[$key]);

                if (is_string($labels)) {
                    return $labels;
                }

                $ids = $adding
                    ? array_merge($ids, $labels)
                    : array_diff($ids, $labels);
            }

            $save['label_ids'] = array_values(array_unique($ids));
            $changed[] = 'labels';
        }

        return ['save' => $save, 'move' => $move, 'changed' => $changed];
    }

    /**
     * A board column by name.
     */
    protected function column(Project $project, mixed $named): BoardColumn|string
    {
        $name = is_string($named) ? trim($named) : '';
        $column = $name === '' ? null : $project->columns()->where('name', 'ilike', $name)->first();

        if ($column !== null) {
            return $column;
        }

        $options = $project->columns()->ordered()->pluck('name')->implode(', ');

        return "{$project->key} has no column called [{$name}]. Its columns are: {$options}. Nothing was changed.";
    }

    /**
     * An open sprint by name, "active" for the running one, or false for
     * "backlog".
     *
     * Open sprints only, as on the board: work cannot be filed into a sprint
     * that has already been closed out.
     */
    protected function sprintNamed(Project $project, mixed $named): Sprint|false|string
    {
        if (! $project->uses_sprints) {
            return "{$project->key} does not use sprints, so tasks there have no sprint. Nothing was changed.";
        }

        $name = is_string($named) ? trim($named) : '';

        if (strtolower($name) === 'backlog') {
            return false;
        }

        $sprint = strtolower($name) === 'active'
            ? $project->sprints()->active()->first()
            : $project->sprints()->open()->where('name', 'ilike', $name)->first();

        if ($sprint !== null) {
            return $sprint;
        }

        $options = $project->sprints()->open()->ordered()->get()
            ->map(fn (Sprint $sprint): string => "{$sprint->name} ({$sprint->state()->value})")
            ->implode(', ');

        $reason = strtolower($name) === 'active'
            ? "{$project->key} has no sprint running."
            : "{$project->key} has no open sprint called [{$name}].";

        return $reason.' '.($options === ''
            ? 'It has no open sprints; use "backlog".'
            : "Its open sprints are: {$options}. \"backlog\" also works.").' Nothing was changed.';
    }

    /**
     * A release by name, or null for "none".
     */
    protected function releaseNamed(Project $project, mixed $named): Release|null|string
    {
        $name = is_string($named) ? trim($named) : '';

        if (strtolower($name) === 'none') {
            return null;
        }

        $release = $name === '' ? null : $project->releases()->where('name', $name)->first();

        if ($release !== null) {
            return $release;
        }

        return "{$project->key} has no release called [{$name}]. ".$this->releaseOptions($project).' Nothing was changed.';
    }

    /**
     * The releases to offer when a name matched none of them.
     */
    protected function releaseOptions(Project $project): string
    {
        $options = $project->releases()->ordered()->limit(20)->get()
            ->map(fn (Release $release): string => "{$release->name} ({$release->state()->value})")
            ->implode(', ');

        return $options === ''
            ? 'It has no releases yet; `create_release` makes one.'
            : "Its releases are: {$options}.";
    }

    /**
     * The task a task goes under, or null for "none".
     *
     * Any story, task, or bug in the same project can be a parent, with the
     * rules {@see SaveTask} enforces: an epic is never under another task, a
     * subtask never has children or sits straight under an epic, and no task
     * can go under itself or one of its own subtasks.
     */
    protected function parentNamed(BiteContext $context, Project $project, mixed $named, TaskType $type, ?Task $task): Task|null|string
    {
        $reference = is_string($named) ? trim($named) : '';

        if (strtolower($reference) === 'none') {
            return $type->isSubtask()
                ? 'A subtask always has a parent. Change its type to task first, or give it another parent. Nothing was changed.'
                : null;
        }

        if ($type->isContainer()) {
            return 'An epic is always at the top and cannot have a parent. Nothing was changed.';
        }

        $parent = $this->task($context, $reference);

        $why = match (true) {
            $parent === null => "There is no task [{$reference}] that this person can see. References look like {$project->key}-12.",
            $parent->project_id !== $project->id => "[{$reference}] is in a different project; a parent must be in {$project->key}.",
            $parent->type->isSubtask() => "[{$reference}] is a subtask, and a subtask cannot have subtasks of its own.",
            ! $parent->type->canHoldChild($type) => "A subtask goes under a story, task, or bug, not straight under an epic like [{$reference}].",
            $task !== null && $parent->isOrDescendsFrom($task->id) => $parent->id === $task->id
                ? 'A task cannot be its own parent.'
                : "[{$reference}] is a subtask of {$task->reference()}, so it cannot also be its parent.",
            default => null,
        };

        return $why === null ? $parent : $why.' Nothing was changed.';
    }

    /**
     * Label ids by name, every one of which has to exist on the project.
     *
     * Labels are not invented here: they are part of how a project is set up,
     * which is its leads' call rather than an assistant's.
     *
     * @return list<int>|string
     */
    protected function labelsNamed(Project $project, mixed $named): array|string
    {
        $names = collect(is_array($named) ? $named : explode(',', (string) $named))
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->values();

        $labels = $project->labels()->get(['id', 'name']);
        $ids = [];
        $missing = [];

        foreach ($names as $name) {
            $label = $labels->first(fn (TaskLabel $label): bool => strcasecmp($label->name, $name) === 0);

            if ($label === null) {
                $missing[] = $name;
            } else {
                $ids[] = $label->id;
            }
        }

        if ($missing === []) {
            return $ids;
        }

        $options = $labels->pluck('name')->sort()->implode(', ');

        return "{$project->key} has no label called [".implode('], [', $missing).']. '
            .($options === '' ? 'It has no labels.' : "Its labels are: {$options}.")
            .' Nothing was changed.';
    }

    /**
     * Whether a MoveTask target would put the task anywhere new.
     *
     * Checked first because MoveTask always re-seats the card, so "move it to
     * the column it is already in" would quietly send it to the bottom.
     *
     * @param  array<string, mixed>  $move
     */
    protected function moves(Task $task, array $move): bool
    {
        if (($move['in_backlog'] ?? false) === true && $task->sprint_id !== null) {
            return true;
        }

        if (array_key_exists('sprint_id', $move) && $move['sprint_id'] !== $task->sprint_id) {
            return true;
        }

        return array_key_exists('board_column_id', $move) && $move['board_column_id'] !== $task->board_column_id;
    }

    /**
     * A due date as Y-m-d, null for "none", or false when it is not a date.
     */
    protected function dueDate(mixed $value): string|null|false
    {
        $due = trim((string) $value);

        if (strtolower($due) === 'none') {
            return null;
        }

        $time = strtotime($due);

        return $time === false ? false : date('Y-m-d', $time);
    }
}
