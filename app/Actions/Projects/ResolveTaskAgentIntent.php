<?php

namespace App\Actions\Projects;

use App\Enums\TaskAgentIntent;
use App\Events\BoardChanged;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Turns the task panel's agent controls into the assignee and labels one
 * {@see SaveTask} edit applies.
 *
 * The dispatcher starts an agent only when the assignee, acting in the web
 * UI, makes a task eligible. So `agent` goes on only in an edit that also
 * makes the actor the assignee; asking for somebody else's agent puts on
 * `agent-requested` instead, which that person later swaps with Start.
 */
class ResolveTaskAgentIntent
{
    /**
     * @return array{assignee_id?: int, label_ids: array<int, int>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, User $actor, TaskAgentIntent $intent, ?int $requestedAssigneeId): array
    {
        $project = $task->project;

        if (! $project->agentsEnabled()) {
            throw ValidationException::withMessages(['agent' => 'Agents are not switched on for this project.']);
        }

        $kept = $task->labels()
            ->whereNotIn('name', TaskLabel::RESERVED)
            ->pluck('task_labels.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($intent === TaskAgentIntent::Stop) {
            return ['label_ids' => $kept];
        }

        $assigneeId = match ($intent) {
            TaskAgentIntent::Start => $this->startingAssignee($task, $actor),
            TaskAgentIntent::Assign => $requestedAssigneeId ?? $task->assignee_id ?? $actor->id,
        };

        if (! $project->members()->where('user_id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Only somebody on the project can be given an agent.']);
        }

        $label = $this->reservedLabel($project, $assigneeId === $actor->id ? TaskLabel::AGENT : TaskLabel::AGENT_REQUESTED);

        return ['assignee_id' => $assigneeId, 'label_ids' => [...$kept, $label->id]];
    }

    /**
     * @throws AuthorizationException
     */
    private function startingAssignee(Task $task, User $actor): int
    {
        if ($task->assignee_id !== $actor->id) {
            throw new AuthorizationException('Only the assignee can start their agent.');
        }

        return $actor->id;
    }

    public function reservedLabel(Project $project, string $name): TaskLabel
    {
        $label = $project->labels()->createOrFirst(
            ['name' => $name],
            ['color' => $name === TaskLabel::AGENT ? 'violet' : 'amber'],
        );

        if ($label->wasRecentlyCreated) {
            BoardChanged::dispatch($project);
        }

        return $label;
    }
}
