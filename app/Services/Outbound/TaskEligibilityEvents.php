<?php

namespace App\Services\Outbound;

use App\Enums\EligibilityReason;
use App\Enums\OutboundEventType;
use App\Models\Channel;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the agent dispatcher when a task's assignee, label set, or the
 * assignee's view of the agent channel changes.
 *
 * Those are what decide whether an agent should be working a task, so every
 * path that can change them reports here: editing a card, filing one,
 * deleting one, taking somebody off the project or the installation, deleting
 * or renaming a label, and changing who can see the agent channel. Moving a
 * card between columns or sprints changes none of them and is not reported.
 *
 * # Lock order
 *
 * {@see transaction()} takes the workspace lock and then the project row, per
 * {@see OutboundEvents}; {@see watch()} then lets the caller lock anything of
 * its own (a label), locks the affected tasks in id order, and only then
 * selects the set it will report on — so a task cannot join that set between
 * being selected and being changed.
 *
 * Each task's "before" is taken there, and its "after" when the transaction
 * flushes, so a task reported once per change carries the change's end state.
 *
 * @phpstan-import-type EligibilitySnapshot from OutboundPayloads
 */
class TaskEligibilityEvents
{
    public function __construct(
        private readonly OutboundEvents $events,
        private readonly OutboundPayloads $payloads,
    ) {}

    /**
     * Run a change to a project's tasks — under the workspace lock when it may
     * change what is reported, in a plain transaction otherwise.
     *
     * Whether to lock is decided by an unlocked read, and decided again once
     * locked: see the accepted gap on {@see OutboundEvents}.
     *
     * @template T
     *
     * @param  bool  $relevant  Whether the change touches anything reported: an assignee, labels, a title or description, a deletion.
     * @param  Closure(Project|null): T  $callback  Given the project, locked and re-read, while its changes are reported; null otherwise.
     * @return T
     */
    public function transaction(Project $project, bool $relevant, Closure $callback): mixed
    {
        if (! $relevant || ! $this->events->enabled() || ! Project::query()->whereKey($project->id)->withAgentsEnabled()->exists()) {
            return DB::transaction(fn (): mixed => $callback(null));
        }

        return $this->events->transaction([$project->workspace_id], function () use ($project, $callback): mixed {
            $locked = Project::query()->with('workspace')->lock('for no key update')->find($project->id);

            return $callback($locked !== null && $locked->agentsEnabled() ? $locked : null);
        });
    }

    /**
     * Run a change to some tasks, reporting each one whose eligibility it
     * changed.
     *
     * The mutation is handed the ids of the tasks that were locked, or null
     * when nothing is being reported and nothing was locked. A bulk change must
     * touch exactly those ids when it has them — re-running its own query could
     * catch a task that was never in the "before".
     *
     * @template T
     *
     * @param  Closure(): Builder<Task>  $affected  Runs after the project is locked; may lock its own rows first.
     * @param  Closure(array<int, int>|null): T  $mutation
     * @return T
     */
    public function track(Project $project, EligibilityReason $reason, Closure $affected, Closure $mutation): mixed
    {
        return $this->transaction($project, true, fn (?Project $locked): mixed => $mutation(
            $locked === null ? null : $this->watch($locked, $reason, $affected),
        ));
    }

    /**
     * Report a freshly filed task, from inside the transaction that filed it.
     */
    public function created(?Project $locked, Task $task): void
    {
        if ($locked !== null) {
            $this->defer($locked, EligibilityReason::Created, [$task->id => [$task->number, null]], new Viewers($locked->agent_channel_id));
        }
    }

    /**
     * Lock the affected tasks and take their "before"; each one is reported
     * when the transaction flushes if its eligibility changed by then.
     *
     * @param  Project  $locked  As handed out by {@see transaction()}.
     * @param  Closure(): Builder<Task>  $affected
     * @param  bool  $viewersMayChange  Whether the change may alter who can see the agent channel; when it cannot, the "after" reuses the "before"'s answers.
     * @return array<int, int> the ids locked
     */
    public function watch(Project $locked, EligibilityReason $reason, Closure $affected, bool $viewersMayChange = false): array
    {
        $ids = $affected()
            ->where('project_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $channel = $locked->agentChannel;
        $viewers = new Viewers($channel?->id);
        $before = [];

        foreach ($this->load($ids) as $task) {
            $before[$task->id] = [$task->number, $this->snapshot($task, $channel, $viewers)];
        }

        $this->defer($locked, $reason, $before, $viewersMayChange ? $viewers->forgettingAnswers() : $viewers);

        return $ids;
    }

    /**
     * Run a change to who can see channels in a workspace — somebody joining,
     * leaving or removed from a channel, or a change of role — and report
     * every open task whose assignee's view of its project's agent channel it
     * changed.
     *
     * @template T
     *
     * @param  int|null  $channelId  The channel whose audience changes; null for every agent channel in the workspace.
     * @param  array<int, int>  $userIds  The people whose view may change.
     * @param  Closure(): T  $mutation
     * @param  Closure(int): bool  $alters  Asked under the lock, per person: can this change alter what they see? Nothing is locked for those it cannot.
     * @return T
     */
    public function aroundViewerChange(int $workspaceId, ?int $channelId, array $userIds, Closure $mutation, Closure $alters): mixed
    {
        return $this->events->transaction([$workspaceId], function () use ($workspaceId, $channelId, $userIds, $mutation, $alters): mixed {
            $userIds = $this->events->enabled() ? array_values(array_filter($userIds, $alters)) : [];

            if ($userIds !== []) {
                Project::query()
                    ->with('workspace')
                    ->where('workspace_id', $workspaceId)
                    ->when(
                        $channelId === null,
                        fn (Builder $query) => $query->whereNotNull('agent_channel_id'),
                        fn (Builder $query) => $query->where('agent_channel_id', $channelId),
                    )
                    ->orderBy('id')
                    ->lock('for no key update')
                    ->get()
                    ->filter(fn (Project $project): bool => $project->agentsEnabled())
                    ->each(fn (Project $project) => $this->watchViewers($project, $userIds));
            }

            return $mutation();
        });
    }

    /**
     * Whether being in the channel is what decides if this person sees it —
     * true of a private channel, and of every channel for a guest. Leaving or
     * joining any other channel changes nothing the dispatcher is told.
     */
    public function membershipDecidesView(int $channelId, int $userId): bool
    {
        $channel = Channel::query()->find($channelId);

        if ($channel === null) {
            return false;
        }

        if ($channel->type->isPrivate()) {
            return true;
        }

        $role = WorkspaceMember::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('user_id', $userId)
            ->first()
            ?->role;

        return $role === null || $role->isGuest();
    }

    /**
     * Watch the open, assigned tasks whose assignee's view of the project's
     * agent channel a change may be about to alter. Only the ones whose
     * snapshot actually changes are reported.
     *
     * @param  Project  $locked  Locked, under the workspace lock, with agents on.
     * @param  array<int, int>|null  $assigneeIds  Only these people's tasks; null for everybody's.
     */
    public function watchViewers(Project $locked, ?array $assigneeIds = null): void
    {
        $this->watch($locked, EligibilityReason::Updated, fn (): Builder => Task::query()
            ->whereNotNull('assignee_id')
            ->whereNull('completed_at')
            ->when($assigneeIds !== null, fn (Builder $query) => $query->whereIn('assignee_id', $assigneeIds)), viewersMayChange: true);
    }

    /**
     * @param  array<int, array{0: int, 1: EligibilitySnapshot|null}>  $before  Number and "before" by task id.
     */
    private function defer(Project $locked, EligibilityReason $reason, array $before, Viewers $viewers): void
    {
        $this->events->defer(OutboundEventType::TaskEligibilityChanged, $locked->workspace_id, function () use ($locked, $reason, $before, $viewers): array {
            /*
             * Re-read, because the change itself may have moved the project —
             * a lead switching its agent channel reports the tasks against the
             * new one, and switching agents off reports nothing.
             */
            $project = Project::query()->with('workspace')->find($locked->id);

            if ($project === null || ! $project->agentsEnabled()) {
                return [];
            }

            $channel = $project->agentChannel;
            $after = $this->load(array_keys($before))->keyBy('id');
            $block = null;
            $events = [];

            foreach ($before as $id => [$number, $beforeSnapshot]) {
                $task = $after->get($id);
                $afterSnapshot = $task === null ? null : $this->snapshot($task, $channel, $viewers);

                if (! $this->changed($beforeSnapshot, $afterSnapshot)) {
                    continue;
                }

                $events[] = [
                    'reason' => $reason->value,
                    'project' => $block ??= $this->payloads->project($project, $channel),
                    'task_id' => $id,
                    'reference' => $project->reference($number),
                    'task' => $task === null ? null : $this->payloads->task($project, $task),
                    'before' => $beforeSnapshot,
                    'after' => $afterSnapshot,
                ];
            }

            return $events;
        });
    }

    /**
     * Whether the assignee can read the agent channel is part of the snapshot:
     * an agent working for somebody who cannot see the thread it is told to
     * work in would be posting where its owner cannot follow, so the receiver
     * treats `false` as not eligible.
     *
     * @return EligibilitySnapshot
     */
    private function snapshot(Task $task, ?Channel $channel, Viewers $viewers): array
    {
        return $this->payloads->eligibility(
            $task,
            $task->assignee_id !== null && $channel !== null && $viewers->canView((int) $task->assignee_id, $channel),
        );
    }

    /**
     * A task that appears or disappears counts only if it carried an assignee
     * or a label: an unassigned, unlabelled card could never have been an
     * agent's, so filing or deleting one tells the receiver nothing.
     *
     * @param  EligibilitySnapshot|null  $before
     * @param  EligibilitySnapshot|null  $after
     */
    private function changed(?array $before, ?array $after): bool
    {
        if ($before !== null && $after !== null) {
            return $before !== $after;
        }

        $only = $before ?? $after;

        return $only !== null && ($only['assignee_id'] !== null || $only['label_ids'] !== []);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Task>
     */
    private function load(array $ids): Collection
    {
        return Task::query()
            ->with(['column:id,name', 'labels'])
            ->whereKey($ids)
            ->orderBy('id')
            ->get();
    }
}
