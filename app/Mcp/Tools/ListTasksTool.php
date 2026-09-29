<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
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
type, or priority. By default this shows what is on the board right now (the
active sprint, for a sprint project) and leaves finished work out.

- `scope`: `board` (default) — the current board; `backlog` — tasks not in any
  sprint; `all` — everything.
- `status`: a column name from `get_project`, such as "In review".
- `assignee`: @username, or "none" for unassigned tasks.

Each row starts with the task reference (ENG-42) that `get_task`,
`update_task`, and `comment_on_task` take.
TEXT)]
#[IsReadOnly]
class ListTasksTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
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
            $person = $this->projectMember($project, $assignee);

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
            'assignee' => $schema->string()->description('Optional: @username, or "none" for unassigned.'),
            'type' => $schema->string()->description('Optional: only this type.')->enum(TaskType::values()),
            'priority' => $schema->string()->description('Optional: only this priority.')->enum(TaskPriority::values()),
            'include_done' => $schema->boolean()->description('Include finished tasks. Defaults to false unless a status is given.'),
            'limit' => $schema->integer()->description('How many to return. Defaults to 50, maximum 100.')->min(1)->max(100),
        ];
    }
}
