<?php

namespace App\Ai\Tools;

use App\Models\Task;
use App\Services\AgentReadScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "What's open on the mobile project?" — and "did we already file this?"
 *
 * The second question is the important one. Bite can create tasks, and a bot
 * that files a duplicate every time somebody mentions a bug is worse than no
 * bot at all, so the instructions require a search before a create and this is
 * what serves it.
 *
 * Scoped to the projects the asker may see, which for anyone but a guest is the
 * workspace's projects — the rule {@see App\Policies\ProjectPolicy} states, and
 * the same one {@see BrowseWorkspace} lists by.
 */
class SearchTasks extends BiteTool implements Tool
{
    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Search the tasks and issues on this workspace's project boards, by words
        in the title or description.

        Always use this before creating a task, to check whether the thing has
        already been filed. Can also answer questions about what is open, what is
        assigned to someone, and what is overdue.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->context->isGuest()) {
            return 'This person is a guest and cannot see projects or tasks.';
        }

        $term = $this->term($this->arg($request, 'query'), 120);
        $limit = $this->limit($this->arg($request, 'limit'), 15, 40);
        $key = $this->term($this->arg($request, 'project'), 20);
        $assignee = ltrim($this->term($this->arg($request, 'assignee'), 60), '@');

        $tasks = Task::query()
            ->whereHas('project', fn (Builder $project) => $project
                ->where('workspace_id', $this->context->workspace->id)
                ->when($key !== '', fn (Builder $query) => $query->where('key', 'ilike', $key))
                ->when($this->context->readScope, fn (Builder $query, AgentReadScope $scope) => $scope->constrainProjects($query)))
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $group) => $group
                    ->where('title', 'ilike', '%'.$term.'%')
                    ->orWhere('description', 'ilike', '%'.$term.'%'),
            ))
            ->when(
                $this->arg($request, 'open_only') === true,
                fn (Builder $query) => $query->whereNull('completed_at'),
            )
            ->when($assignee !== '', fn (Builder $query) => $query->whereHas(
                'assignee',
                fn (Builder $user) => $user->where('username', 'ilike', $assignee),
            ))
            ->with(['project:id,key,name', 'assignee:id,name,username', 'column:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return $this->list(
            $tasks->map(fn (Task $task): string => $this->row($task))->all(),
            $term === ''
                ? 'There are no tasks matching that.'
                : "No tasks match [{$term}].",
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Words from the task title or description.'),
            'project' => $schema->string()
                ->description('Optional: limit to one project, by its key (for example ENG). Get keys from browse_workspace.'),
            'assignee' => $schema->string()
                ->description('Optional: limit to tasks assigned to this username.'),
            'open_only' => $schema->boolean()
                ->description('Optional: only tasks that are not yet complete.'),
            'limit' => $schema->integer()
                ->description('How many tasks to return. Defaults to 15, maximum 40.')
                ->min(1)
                ->max(40),
        ];
    }

    private function row(Task $task): string
    {
        $assignee = $task->assignee !== null
            ? "assigned to {$task->assignee->name}"
            : 'unassigned';

        $state = $task->isComplete() ? 'done' : $task->column->name;
        $due = $task->due_on !== null ? ", due {$task->due_on->toFormattedDateString()}" : '';

        return "{$task->reference()} — {$task->title}"
            ."\n  {$task->type->value}, {$task->priority->value} priority, {$state}, {$assignee}{$due}";
    }
}
