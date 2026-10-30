<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Mcp\Tools\Concerns\ScopesAgentReads;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The cards on a board, filtered the ways people ask: "what's in review",
 * "what is Ada working on", "what's left in the backlog".
 *
 * `search_tasks` answers "is there a task about X" across every project; this
 * answers "what is on this board", and so starts from the board rather than
 * from words.
 */
#[Name('list_tasks')]
#[Title('List Tasks')]
#[Description(<<<'TEXT'
List the tasks on one project, optionally filtered by status column, assignee,
type, priority, sprint, release, or parent task. By default this shows what is
on the board right now (the active sprint, for a sprint project) and leaves
finished work out.

- `scope`: `board` (default) — the current board; `backlog` — tasks not in any
  sprint; `all` — everything.
- `status`: a column name from `get_project`, such as "In review".
- `assignee`: @username, "me" for the connected person, or "none" for
  unassigned tasks.
- `sprint`: "active", an open sprint's name, or "backlog". Overrides `scope`.
- `release`: a release name such as "v3.2.1", or "none" for unscheduled work.
  Overrides `scope`.
- `parent`: a task's reference, to list its subtasks. Overrides `scope`.

Each row starts with the task reference (ENG-42) that `get_task`,
`update_task`, and `comment_on_task` take.
TEXT)]
#[IsReadOnly]
class ListTasksTool extends SnackTool
{
    use PlacesTasks;
    use ResolvesProjectWork;
    use ScopesAgentReads;

    public function handle(Request $request): Response
    {
        $context = $this->readContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $project = $this->project($context, $request->get('project'));

        if ($project === null) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        $scope = $request->get('scope', 'board');
        $status = is_string($request->get('status')) ? trim($request->get('status')) : '';
        $assignee = is_string($request->get('assignee')) ? trim($request->get('assignee')) : '';
        $limit = max(1, min((int) ($request->get('limit') ?: 50), 100));

        $query = match ($scope) {
            'backlog' => $project->tasks()->getQuery()->whereNull('sprint_id'),
            'all' => $project->tasks()->getQuery(),
            default => $project->boardTasks(),
        };

        /*
         * Naming a sprint, release, or parent already says which tasks are
         * meant, so it widens the query to the whole project rather than
         * intersecting with whatever `scope` defaulted to.
         */
        if (array_filter([$request->get('sprint'), $request->get('release'), $request->get('parent')], is_string(...)) !== []) {
            $query = $project->tasks()->getQuery();
        }

        if (is_string($request->get('sprint'))) {
            $sprint = $this->sprintNamed($project, $request->get('sprint'));

            if (is_string($sprint)) {
                return Response::error(str_replace(' Nothing was changed.', '', $sprint));
            }

            $query->where('sprint_id', $sprint === false ? null : $sprint->id);
        }

        if (is_string($request->get('release'))) {
            $release = $this->releaseNamed($project, $request->get('release'));

            if (is_string($release)) {
                return Response::error(str_replace(' Nothing was changed.', '', $release));
            }

            $query->where('release_id', $release?->id);
        }

        if (is_string($request->get('parent'))) {
            $parent = $this->parentNamed($context, $project, $request->get('parent'), TaskType::Task, null);

            if (! $parent instanceof Task) {
                return Response::error(str_replace(' Nothing was changed.', '', $parent ?? 'Name a task, such as ENG-12.'));
            }

            $query->where('parent_task_id', $parent->id);
        }

        if ($status !== '') {
            $column = $project->columns()->where('name', 'ilike', $status)->first();

            if ($column === null) {
                return Response::error("{$project->key} has no column called [{$status}]. Use `get_project` to see its columns.");
            }

            $query->where('board_column_id', $column->id);
        } elseif (! $request->get('include_done', false)) {
            $query->whereNull('completed_at');
        }

        if (strtolower($assignee) === 'none') {
            $query->whereNull('assignee_id');
        } elseif ($assignee !== '') {
            $person = $this->projectMember($project, $assignee, $context->user);

            if ($person === null) {
                return Response::error("Nobody on {$project->key} matches [{$assignee}].");
            }

            $query->where('assignee_id', $person->id);
        }

        $query
            ->when(TaskType::tryFrom((string) $request->get('type')), fn (Builder $q, TaskType $type) => $q->where('type', $type))
            ->when(TaskPriority::tryFrom((string) $request->get('priority')), fn (Builder $q, TaskPriority $priority) => $q->where('priority', $priority));

        $total = (clone $query)->count();

        $rows = $query
            ->with(['column:id,name', 'assignee:id,name,username'])
            ->leftJoin('board_columns', 'board_columns.id', '=', 'tasks.board_column_id')
            ->orderBy('board_columns.position')
            ->orderBy('tasks.position')
            ->orderBy('tasks.id')
            ->select('tasks.*')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task): string => sprintf(
                '%s — %s [%s · %s · %s] %s',
                $project->reference($task->number),
                $task->title,
                $task->column->name,
                $task->type->value,
                $task->priority->value,
                $task->assignee !== null ? "@{$task->assignee->username}" : 'unassigned',
            ))
            ->all();

        if ($rows === []) {
            return Response::text("No tasks on {$project->key} match.");
        }

        return Response::text(implode("\n", $rows)
            ."\n\n".($total > count($rows) ? 'Showing '.count($rows)." of {$total}." : "{$total} task(s)."));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'scope' => $schema->string()->description('Which tasks: board (default), backlog, or all.')->enum(['board', 'backlog', 'all']),
            'status' => $schema->string()->description('Optional: only tasks in this column, by name.'),
            'assignee' => $schema->string()->description('Optional: @username, "me", or "none" for unassigned.'),
            'type' => $schema->string()->description('Optional: only this type.')->enum(TaskType::values()),
            'priority' => $schema->string()->description('Optional: only this priority.')->enum(TaskPriority::values()),
            'sprint' => $schema->string()->description('Optional: "active", an open sprint\'s name, or "backlog".'),
            'release' => $schema->string()->description('Optional: a release name, or "none" for unscheduled work.'),
            'parent' => $schema->string()->description('Optional: a task\'s reference, to list its subtasks.'),
            'include_done' => $schema->boolean()->description('Include finished tasks. Defaults to false unless a status is given.'),
            'limit' => $schema->integer()->description('How many to return. Defaults to 50, maximum 100.')->min(1)->max(100),
        ];
    }
}
