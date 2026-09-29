<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * "What's on my plate?" — every task assigned to the connected person, across
 * every project in the workspace, in one call.
 *
 * `list_tasks` works one board at a time; answering this with it means asking
 * which account is "me" and then walking every project. This does both.
 */
#[Name('list_my_tasks')]
#[Title('List My Tasks')]
#[Description(<<<'TEXT'
List the tasks assigned to the person you are connected as, across all
projects in the workspace. Open tasks only, unless `include_done` is set.
Tasks on the backlog of a sprint project are marked. Each row starts with the
task reference (ENG-42) that `get_task` and `update_task` take.
TEXT)]
#[IsReadOnly]
class ListMyTasksTool extends SnackTool
{
    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $limit = max(1, min((int) ($request->get('limit') ?: 50), 100));

        $tasks = Task::query()
            ->where('assignee_id', $context->user->id)
            ->whereHas('project', fn (Builder $project) => $project
                ->where('workspace_id', $context->workspace->id)
                ->where('is_archived', false))
            ->when(! $request->get('include_done', false), fn (Builder $query) => $query->whereNull('completed_at'))
            ->with(['project', 'column:id,name', 'sprint:id,name'])
            // Soonest due first; undated work after, oldest first.
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get()
            // Per project rather than in SQL: the rule is the policy's, and a
            // guest who was once assigned something may since have lost the
            // project.
            ->filter(fn (Task $task): bool => Gate::forUser($context->user)->allows('view', $task));

        $more = $tasks->count() > $limit;

        $rows = $tasks->take($limit)->map(fn (Task $task): string => sprintf(
            '%s — %s [%s · %s · %s%s]%s',
            $task->reference(),
            $task->title,
            $task->project->name,
            $task->column->name,
            $task->priority->value,
            $task->due_on !== null ? ' · due '.$task->due_on->toFormattedDateString() : '',
            $task->project->uses_sprints && $task->sprint_id === null ? ' (backlog)' : '',
        ))->values()->all();

        if ($rows === []) {
            return Response::text("{$context->user->name} has no open tasks assigned.");
        }

        return Response::text("Tasks assigned to {$context->user->name} (@{$context->user->username}):\n\n"
            .implode("\n", $rows)
            .($more ? "\n\nShowing the first {$limit}; there are more." : ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_done' => $schema->boolean()->description('Include finished tasks. Defaults to false.'),
            'limit' => $schema->integer()->description('How many to return. Defaults to 50, maximum 100.')->min(1)->max(100),
        ];
    }
}
