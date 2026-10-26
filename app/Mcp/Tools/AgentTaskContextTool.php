<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Mcp\Tools\Concerns\ServesAgents;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\TaskLabel;
use App\Models\User;
use App\Services\Outbound\OutboundPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A task and its project's agent settings as data: what the Kusina
 * daemon checks before it plans, posts or executes — who the task belongs to,
 * whether agents are on, and where they talk.
 *
 * Resolved like `get_task`, so a task the person cannot see does not exist.
 * Agents count as on by the same rule events use ({@see Project::agentsEnabled()});
 * the channel — and with it the columns — is named only to someone who can
 * see it, and `enabled` stays true either way.
 */
#[Name('agent_task_context')]
#[Title('Agent Task Context')]
#[Description(<<<'TEXT'
For agent connectors only: one task and its project's agent settings as one
JSON object. Pass the task reference, such as ENG-42.
TEXT)]
#[IsReadOnly]
class AgentTaskContextTool extends SnackTool
{
    use ResolvesProjectWork;
    use ServesAgents;

    public function handle(Request $request, OutboundPayloads $payloads): Response
    {
        $context = $this->agentContext($request, 'agent_task_context');

        if ($context instanceof Response) {
            return $context;
        }

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $task->load(['assignee:id,username', 'labels']);

        /** @var Project $project */
        $project = $task->project;
        $project->loadMissing(['workspace', 'agentChannel.workspace', 'agentInProgressColumn', 'agentReviewColumn']);

        $enabled = $project->agentsEnabled();
        $channel = $enabled && Gate::forUser($context->user)->allows('view', $project->agentChannel) ? $project->agentChannel : null;
        $reference = $task->reference();

        return $this->json([
            'viewer' => $this->person($context->user),
            'task' => [
                'id' => $task->id,
                'reference' => $reference,
                'title' => $task->title,
                'description' => $task->description,
                'assignee' => $task->assignee === null ? null : $this->person($task->assignee),
                'reporter_id' => $task->reporter_id,
                'label_names' => $task->labels->sortBy('id')->map(fn (TaskLabel $label): string => $label->name)->values()->all(),
                'url' => $payloads->taskUrl($project, $reference),
            ],
            'project' => [
                'id' => $project->id,
                'key' => $project->key,
                'archived' => $project->is_archived,
                'repository_url' => $project->repository_url,
                'repository_base_branch' => $project->repository_base_branch,
                'agents' => [
                    'enabled' => $enabled,
                    'channel' => $channel === null ? null : ['id' => $channel->id, 'name' => $channel->name],
                    // Named only alongside the channel, as get_project does.
                    'in_progress_column' => $channel === null ? null : $this->column($project->agentInProgressColumn),
                    'review_column' => $channel === null ? null : $this->column($project->agentReviewColumn),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
        ];
    }

    /**
     * @return array{id: int, username: string}
     */
    private function person(User $user): array
    {
        return ['id' => $user->id, 'username' => $user->username];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function column(?BoardColumn $column): ?array
    {
        return $column === null ? null : ['id' => $column->id, 'name' => $column->name];
    }
}
