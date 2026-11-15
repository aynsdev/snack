<?php

namespace App\Actions\Projects;

use App\Enums\TaskAgentIntent;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Several selected cards, changed the same way at once: moved to a sprint or
 * the backlog, handed to an agent, or both.
 *
 * # One path through the server, many times
 *
 * Each card goes through exactly the actions a single edit uses —
 * {@see MoveTask} for the sprint, {@see ResolveTaskAgentIntent} and
 * {@see SaveTask} for the agent — so the reserved-label rules, the eligibility
 * events the agent dispatcher listens for, the assignment notifications and the
 * `TaskChanged` broadcasts are identical to doing it card by card. A bulk path
 * with its own writes would be the copy that misses the next rule.
 *
 * # Checked first, then written
 *
 * Everything that could refuse — a card from another project, a closed sprint,
 * agents switched off, an assignee who is not on the project — is settled
 * before the first write, so a refusal changes nothing. The same shape as the
 * MCP tool `update_tasks`.
 *
 * # The sprint before the agent
 *
 * A card handed to an agent can start work the moment the assignee's agent sees
 * it, so it is put in its sprint first: the agent then finds it where it will be
 * worked, not in the backlog it is about to leave.
 */
class BulkUpdateTasks
{
    /**
     * The most cards one request may touch — a sprint's worth, and the same
     * bound the MCP tool `update_tasks` uses.
     */
    public const MAX_TASKS = 50;

    public function __construct(
        private readonly MoveTask $moveTask,
        private readonly SaveTask $saveTask,
        private readonly ResolveTaskAgentIntent $agentIntent,
    ) {}

    /**
     * @param  array<int, int>  $taskIds
     * @param  array{sprint_id?: int|null, in_backlog?: bool}|null  $placement  null leaves the sprint alone
     * @param  array{assignee_id: int|null}|null  $agent  null leaves the agent alone
     * @return array{tasks: Collection<int, Task>, skipped: Collection<int, Task>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Project $project, User $actor, array $taskIds, ?array $placement, ?array $agent): array
    {
        $tasks = $this->tasks($project, $taskIds);

        foreach ($tasks as $task) {
            Gate::forUser($actor)->authorize('update', $task);
        }

        $target = $placement === null ? null : $this->target($project, $placement);
        $assigneeId = $agent === null ? null : $this->agentAssignee($project, $actor, $agent['assignee_id']);

        /*
         * A card that already has an agent — working, or asked for — keeps it.
         * Re-assigning would swap a running agent's label from under it; the
         * card's own panel is where that is done on purpose.
         */
        $skipped = $agent === null
            ? collect()
            : $tasks->filter(fn (Task $task): bool => $task->labels->whereIn('name', TaskLabel::RESERVED)->isNotEmpty())->values();

        $changed = $tasks->map(function (Task $task) use ($actor, $target, $agent, $assigneeId, $skipped): Task {
            if ($target !== null && $this->moves($task, $target)) {
                $task = $this->moveTask->handle($task, $target);
            }

            if ($agent !== null && ! $skipped->contains('id', $task->id)) {
                $task = $this->saveTask->update(
                    $task,
                    $actor,
                    $this->agentIntent->handle($task, $actor, TaskAgentIntent::Assign, $assigneeId),
                );
            }

            return $task;
        });

        return ['tasks' => $changed, 'skipped' => $skipped];
    }

    /**
     * The selected cards, every one of them on this project.
     *
     * @param  array<int, int>  $taskIds
     * @return Collection<int, Task>
     *
     * @throws ValidationException
     */
    private function tasks(Project $project, array $taskIds): Collection
    {
        $ids = array_values(array_unique($taskIds));

        $tasks = $project->tasks()->whereKey($ids)->with('labels')->orderBy('id')->get();

        if ($tasks->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'task_ids' => 'Some of those tasks are not on this project. Nothing was changed.',
            ]);
        }

        return $tasks->each(fn (Task $task) => $task->setRelation('project', $project));
    }

    /**
     * Where the cards go: an open sprint of this project, or the backlog.
     *
     * Refused rather than ignored when the sprint is closed or not this
     * project's — {@see MoveTask} on its own quietly keeps a card where it was,
     * which for one dragged card is the right answer and for a selection is a
     * request that reports success and did nothing.
     *
     * @param  array{sprint_id?: int|null, in_backlog?: bool}  $placement
     * @return array{sprint_id?: int, in_backlog?: true}
     *
     * @throws ValidationException
     */
    private function target(Project $project, array $placement): array
    {
        if (! $project->uses_sprints) {
            throw ValidationException::withMessages(['sprint_id' => 'This project does not use sprints.']);
        }

        if (($placement['in_backlog'] ?? false) === true) {
            return ['in_backlog' => true];
        }

        $sprint = $project->sprints()->open()->whereKey((int) ($placement['sprint_id'] ?? 0))->first();

        if (! $sprint instanceof Sprint) {
            throw ValidationException::withMessages([
                'sprint_id' => 'That sprint is finished or is not on this project. Nothing was changed.',
            ]);
        }

        return ['sprint_id' => $sprint->id];
    }

    /**
     * Whose agent the cards go to: the person named, or the person asking.
     *
     * Checked once here so a refusal comes before any card has changed;
     * {@see ResolveTaskAgentIntent} checks the same again for each card.
     *
     * @throws ValidationException
     */
    private function agentAssignee(Project $project, User $actor, ?int $assigneeId): int
    {
        if (! $project->agentsEnabled()) {
            throw ValidationException::withMessages(['agent' => 'Agents are not switched on for this project.']);
        }

        $assigneeId ??= $actor->id;

        if (! $project->members()->where('user_id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Only somebody on the project can be given an agent.']);
        }

        return $assigneeId;
    }

    /**
     * @param  array{sprint_id?: int, in_backlog?: true}  $target
     */
    private function moves(Task $task, array $target): bool
    {
        return isset($target['in_backlog'])
            ? $task->sprint_id !== null
            : $task->sprint_id !== ($target['sprint_id'] ?? null);
    }
}
