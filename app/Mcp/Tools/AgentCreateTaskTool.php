<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\ResolveTaskAgentIntent;
use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\ServesAgents;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Services\Outbound\OutboundPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Files the ticket an `@agent` conversation agreed on, as the agent's owner.
 *
 * `intent: agent` puts the `agent` label on only when the owner is also the
 * assignee — the same rule the task panel follows — and asks with
 * `agent-requested` otherwise, so an agent can never start somebody else's.
 */
#[Name('agent_create_task')]
#[Title('Agent Create Task')]
#[Description(<<<'TEXT'
For agent connectors only: files a task as this person, as
{"task_id", "reference", "url"}. `assignee` is "owner" or a project member's
user id; `intent` is "agent" (start the owner's agent), "agent-requested"
(ask the assignee to start theirs) or "none". Optional `type` (story, task,
bug, epic; default story) and `priority` (highest, high, medium, low, lowest;
default medium).
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class AgentCreateTaskTool extends SnackTool
{
    use ServesAgents;

    private const INTENTS = ['agent', 'agent-requested', 'none'];

    /**
     * Every type but a subtask, which needs a parent this tool does not take.
     *
     * @return array<int, string>
     */
    private static function types(): array
    {
        return array_values(array_map(
            fn (TaskType $type): string => $type->value,
            array_filter(TaskType::cases(), fn (TaskType $type): bool => ! $type->isSubtask()),
        ));
    }

    public function handle(Request $request, SaveTask $saveTask, ResolveTaskAgentIntent $agentIntent, OutboundPayloads $payloads): Response
    {
        $context = $this->agentContext($request, 'agent_create_task');

        if ($context instanceof Response) {
            return $context;
        }

        $request->validate([
            'project_key' => ['required', 'string', 'max:10'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignee' => ['required'],
            'intent' => ['required', Rule::in(self::INTENTS)],
            'type' => ['nullable', 'string', Rule::in(self::types())],
            'priority' => ['nullable', 'string', Rule::in(TaskPriority::values())],
        ]);

        $owner = $context->user;

        $project = Project::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('key', strtoupper((string) $request->get('project_key')))
            ->with('workspace')
            ->first();

        if ($project === null || Gate::forUser($owner)->denies('view', $project)) {
            return Response::error('There is no project with that key that this person can see.');
        }

        $allowed = Gate::forUser($owner)->inspect('create', [Task::class, $project]);

        if ($allowed->denied()) {
            return Response::error("This person cannot create tasks on {$project->key}: {$allowed->message()}");
        }

        $assignee = $request->get('assignee');
        $assigneeId = match (true) {
            $assignee === 'owner' => $owner->id,
            is_int($assignee) => $assignee,
            is_string($assignee) && ctype_digit($assignee) => (int) $assignee,
            default => null,
        };

        if ($assigneeId === null || ! $project->members()->where('user_id', $assigneeId)->exists()) {
            return Response::error('The assignee must be "owner" or the id of somebody on the project.');
        }

        $intent = (string) $request->get('intent');
        $labelIds = [];

        if ($intent !== 'none') {
            if (! $project->agentsEnabled()) {
                return Response::error("Agents are not switched on for {$project->key}.");
            }

            $name = $intent === 'agent' && $assigneeId === $owner->id ? TaskLabel::AGENT : TaskLabel::AGENT_REQUESTED;
            $labelIds[] = $agentIntent->reservedLabel($project, $name)->id;
        }

        $attributes = [
            'title' => trim((string) $request->get('title')),
            'description' => $request->get('description'),
            'assignee_id' => $assigneeId,
            'label_ids' => $labelIds,
        ];

        /*
         * Left out when not given, so an omitted type or priority is whatever
         * SaveTask files any new card as, rather than a second copy of that
         * default here.
         */
        foreach (['type', 'priority'] as $key) {
            if (is_string($request->get($key))) {
                $attributes[$key] = $request->get($key);
            }
        }

        $task = $saveTask->create($project, $owner, $attributes);

        $reference = $project->reference($task->number);

        return $this->json([
            'task_id' => $task->id,
            'reference' => $reference,
            'url' => $payloads->taskUrl($project, $reference),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->description('The project to file on, by key (DEMO).')->required(),
            'title' => $schema->string()->description('The ticket title.')->required(),
            'description' => $schema->string()->description('The ticket description.'),
            'assignee' => $schema->string()->description('"owner", or a project member\'s user id (as a number or digits).')->required(),
            'intent' => $schema->string()->enum(self::INTENTS)->description('Which agent label to put on.')->required(),
            'type' => $schema->string()->enum(self::types())->description('What kind of work this is. Defaults to story.'),
            'priority' => $schema->string()->enum(TaskPriority::values())->description('How urgent it is. Defaults to medium.'),
        ];
    }
}
