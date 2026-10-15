<?php

namespace App\Actions\Projects;

use App\Enums\ColumnCategory;
use App\Enums\TaskType;
use App\Events\TaskChanged;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Support\Positions;
use Illuminate\Support\Facades\DB;

/**
 * Files a task, and edits one.
 *
 * One action for both because everything that makes a task *valid* is the same
 * on the way in and on the way back: the assignee has to be on the project, the
 * parent has to be in this project and not form a loop, the labels have to be this project's
 * labels, and a task in a Done column has to be marked complete. Splitting them
 * meant those five rules had two implementations, and the edit path is the one
 * that would have quietly grown a hole.
 *
 * # Every id in the payload is re-resolved
 *
 * Column, sprint, epic, assignee, and labels all arrive from the browser. Each
 * is looked up *within this project* and dropped if it does not belong — so a
 * request naming another project's column cannot move a card off its own board,
 * and one naming a colleague who is not on the project cannot make them
 * responsible for it. This is the same rule the rest of Snack applies to any
 * identifier that did not come from the current request's authorization.
 */
class SaveTask
{
    public function __construct(
        private readonly AnnounceAssignment $assignments,
        private readonly AnnounceParentProgress $parents,
    ) {}

    /**
     * @param  array{
     *     title?: string,
     *     description?: string|null,
     *     type?: TaskType|string,
     *     priority?: string,
     *     board_column_id?: int|null,
     *     sprint_id?: int|null,
     *     parent_task_id?: int|null,
     *     assignee_id?: int|null,
     *     story_points?: int|null,
     *     due_on?: string|null,
     *     label_ids?: array<int, int>,
     *  }  $attributes
     */
    public function create(Project $project, User $reporter, array $attributes): Task
    {
        $column = $this->column($project, $attributes['board_column_id'] ?? null);

        $attributes = $this->inheritFromParent($project, $attributes);

        $task = DB::transaction(function () use ($project, $reporter, $attributes, $column): Task {
            $sprint = $this->sprint($project, $attributes['sprint_id'] ?? null);
            $type = $this->type($attributes);
            $parentId = $this->parentId($project, $attributes, $type);

            $task = Task::create([
                'project_id' => $project->id,
                'number' => $this->nextNumber($project),
                'board_column_id' => $column->id,
                'sprint_id' => $sprint?->id,
                'parent_task_id' => $parentId,
                // A subtask is always a piece of something; filed with no
                // parent, it is an ordinary task.
                'type' => $type->isSubtask() && $parentId === null ? TaskType::Task : $type,
                'priority' => $attributes['priority'] ?? 'medium',
                'title' => $attributes['title'] ?? 'Untitled',
                'description' => $attributes['description'] ?? null,
                'assignee_id' => $this->assigneeId($project, $attributes),
                'release_id' => $this->releaseId($project, $attributes),
                'reporter_id' => $reporter->id,
                // Appended, not prepended: a card filed while somebody is
                // working the top of the column must not jump the queue.
                'position' => $this->nextPosition($project, $column->id, $sprint?->id),
                'story_points' => $attributes['story_points'] ?? null,
                'due_on' => $attributes['due_on'] ?? null,
                'completed_at' => $column->isDone() ? now() : null,
            ]);

            $this->syncLabels($project, $task, $attributes);

            return $task;
        });

        event(TaskChanged::for($task->refresh(), 'created'));
        $this->parents->announce([$task->parent_task_id]);

        // Outside the transaction: the notification is queued, and a worker can
        // pick the job up before an open transaction has committed the row it
        // describes.
        $this->assignments->handle($task, $reporter, previousAssigneeId: null);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Task $task, User $actor, array $attributes): Task
    {
        $project = $task->project;
        $previousAssigneeId = $task->assignee_id;
        $previousParentId = $task->parent_task_id;
        $previousType = $task->type;

        DB::transaction(function () use ($project, $task, $attributes, $previousType): void {
            $changes = [];

            foreach (['title', 'description', 'priority', 'story_points', 'due_on'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $changes[$field] = $attributes[$field];
                }
            }

            if (array_key_exists('type', $attributes)) {
                $changes['type'] = $this->type($attributes);
            }

            if (array_key_exists('assignee_id', $attributes)) {
                $changes['assignee_id'] = $this->assigneeId($project, $attributes);
            }

            if (array_key_exists('release_id', $attributes)) {
                $changes['release_id'] = $this->releaseId($project, $attributes);
            }

            if (array_key_exists('parent_task_id', $attributes)) {
                $changes['parent_task_id'] = $this->parentId(
                    $project,
                    $attributes,
                    array_key_exists('type', $attributes) ? $this->type($attributes) : $task->type,
                    $task,
                );
            }

            $task->fill($changes)->save();

            /*
             * An epic is never inside another task, so a task promoted to Epic
             * loses its parent. Done after the fill rather than inside it,
             * because the promotion and the parent can arrive in the same
             * request and the order they were listed in must not decide.
             */
            if ($task->type->isContainer() && $task->parent_task_id !== null) {
                $task->forceFill(['parent_task_id' => null])->save();
            }

            $this->keepHierarchyValid($task, $previousType);

            $this->syncLabels($project, $task, $attributes);
        });

        event(TaskChanged::for($task->refresh(), 'updated'));
        $this->parents->announce([$previousParentId, $task->parent_task_id]);

        $this->assignments->handle($task, $actor, $previousAssigneeId);

        return $task;
    }

    /**
     * A subtask goes where its parent is, unless the request said otherwise.
     *
     * The same as Jira: work broken out of a card is part of that card, so it
     * lands in the parent's sprint and release rather than in the backlog,
     * where nobody working the sprint would see it. Only fills what the
     * request left out — an explicit `sprint_id: null` still means backlog.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function inheritFromParent(Project $project, array $attributes): array
    {
        $parentId = $this->parentId($project, $attributes, $this->type($attributes));

        if ($parentId === null) {
            return $attributes;
        }

        $parent = $project->tasks()->whereKey($parentId)->first(['id', 'sprint_id', 'release_id']);

        if (! array_key_exists('sprint_id', $attributes)) {
            $attributes['sprint_id'] = $parent?->sprint_id;
        }

        if (! array_key_exists('release_id', $attributes)) {
            $attributes['release_id'] = $parent?->release_id;
        }

        return $attributes;
    }

    /**
     * The next reference number in this project.
     *
     * One statement, in the database, with RETURNING — so two people filing a
     * task at the same instant get different numbers. Read-then-write in PHP
     * would hand them both the same one, and the unique index would turn that
     * into an error on somebody's perfectly ordinary click.
     */
    private function nextNumber(Project $project): int
    {
        $row = DB::selectOne(
            'UPDATE projects SET next_task_number = next_task_number + 1 WHERE id = ? RETURNING next_task_number',
            [$project->id],
        );

        // RETURNING gives the value *after* the increment, and the number being
        // handed out is the one that was there before it — so a project whose
        // counter starts at 1 files its first task as 1.
        return (int) $row->next_task_number - 1;
    }

    /**
     * Where a task lands when the request did not say, or named a column that is
     * not this project's: the first unstarted column, and failing that the first
     * column there is.
     */
    private function column(Project $project, ?int $columnId): BoardColumn
    {
        if ($columnId !== null) {
            $column = $project->columns()->whereKey($columnId)->first();

            if ($column !== null) {
                return $column;
            }
        }

        $columns = $project->columns()->ordered()->get();

        $unstarted = $columns->first(
            fn (BoardColumn $column): bool => $column->category === ColumnCategory::Unstarted,
        );

        return $unstarted ?? $columns->firstOrFail();
    }

    private function sprint(Project $project, ?int $sprintId): ?Sprint
    {
        if ($sprintId === null || ! $project->uses_sprints) {
            return null;
        }

        // Open sprints only. Filing straight into a sprint that has already been
        // completed would put a brand-new card into a closed record of what the
        // team did that fortnight.
        return $project->sprints()->open()->whereKey($sprintId)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function type(array $attributes): TaskType
    {
        $type = $attributes['type'] ?? TaskType::Story;

        return $type instanceof TaskType ? $type : TaskType::from((string) $type);
    }

    /**
     * The assignee, if they are on the project.
     *
     * A named person who is not a member is dropped to null rather than refused:
     * this is reachable by removing somebody from the project in one tab while
     * their name is still on an open card in another, and losing the whole edit
     * over a stale picker would be worse than losing the assignment it no longer
     * has the right to make.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assigneeId(Project $project, array $attributes): ?int
    {
        $assigneeId = $attributes['assignee_id'] ?? null;

        if ($assigneeId === null) {
            return null;
        }

        return $project->members()->where('user_id', (int) $assigneeId)->exists()
            ? (int) $assigneeId
            : null;
    }

    /**
     * The task this one sits under, if it may.
     *
     * Any story, task, or bug in this same project can be a parent, so work
     * can be broken down as far as a team needs; an epic can hold anything but
     * a subtask, and a subtask holds nothing — see TaskType::canHoldChild().
     * A task also cannot go under itself or one of its own subtasks, which
     * would make a loop. `$type` is the type the task being saved will have;
     * `$task` is the task itself, when editing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function parentId(Project $project, array $attributes, TaskType $type, ?Task $task = null): ?int
    {
        $parentId = $attributes['parent_task_id'] ?? null;

        if ($parentId === null) {
            return null;
        }

        $parent = $project->tasks()->whereKey((int) $parentId)->first();

        if ($parent === null || ! $parent->type->canHoldChild($type)) {
            return null;
        }

        if ($task !== null && $parent->isOrDescendsFrom($task->id)) {
            return null;
        }

        return $parent->id;
    }

    /**
     * The type rules that depend on the rest of the hierarchy, applied after
     * an edit because a type and a parent can change in the same request.
     *
     * A subtask left without a parent becomes a task. A card with subtasks
     * cannot become a subtask itself, nor an epic (subtasks never sit
     * directly under one), so it keeps the type it had.
     */
    private function keepHierarchyValid(Task $task, TaskType $previousType): void
    {
        if ($task->type->isSubtask() && $task->parent_task_id === null) {
            $task->forceFill(['type' => TaskType::Task])->save();

            return;
        }

        $breaksChildren = match (true) {
            $task->type->isSubtask() => $task->children()->exists(),
            $task->type->isContainer() => $task->children()->where('type', TaskType::Subtask)->exists(),
            default => false,
        };

        if ($breaksChildren) {
            $task->forceFill(['type' => $previousType])->save();
        }
    }

    /**
     * The release this card goes out in, if it is this project's.
     *
     * Silently null for an id belonging to somebody else's project rather than
     * an error, the same as an assignee who is not a member: the id came from a
     * picker, and a picker showing a stale list is a race, not a mistake to
     * scold anybody for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function releaseId(Project $project, array $attributes): ?int
    {
        $releaseId = $attributes['release_id'] ?? null;

        if ($releaseId === null) {
            return null;
        }

        return $project->releases()->whereKey((int) $releaseId)->exists()
            ? (int) $releaseId
            : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncLabels(Project $project, Task $task, array $attributes): void
    {
        if (! array_key_exists('label_ids', $attributes)) {
            return;
        }

        $ids = $project->labels()
            ->whereIn('id', (array) $attributes['label_ids'])
            ->pluck('id')
            ->all();

        $task->labels()->sync($ids);
    }

    /**
     * The bottom of the destination list.
     */
    private function nextPosition(Project $project, int $columnId, ?int $sprintId): int
    {
        $last = $project->tasks()
            ->where('board_column_id', $columnId)
            ->where(fn ($query) => $sprintId === null
                ? $query->whereNull('sprint_id')
                : $query->where('sprint_id', $sprintId))
            ->max('position');

        return Positions::between($last, null);
    }
}
