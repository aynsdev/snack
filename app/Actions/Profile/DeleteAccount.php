<?php

namespace App\Actions\Profile;

use App\Enums\EligibilityReason;
use App\Enums\OutboundEventType;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActorContext;
use App\Services\Outbound\OutboundEvents;
use App\Services\Outbound\TaskEligibilityEvents;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Deletes a person's account.
 *
 * The database would unassign their tasks on its own (`nullOnDelete`), and
 * drop their workspace memberships with the row — silently, so the agent
 * dispatcher would go on routing those tasks to an agent whose owner no longer
 * exists. So both are done here first, in the same transaction as the delete:
 * every assigned task is handed back through the eligibility pipeline, and
 * every workspace they belonged to is told they are gone.
 *
 * Takes the person as the actor explicitly, because the caller has already
 * signed them out by the time this runs.
 *
 * Every workspace it reports to is locked before anything is written, in
 * ascending order — see OutboundEvents.
 */
class DeleteAccount
{
    /**
     * How many times to start over when the person joins another workspace
     * mid-deletion — enough for an invitation accepted at the same moment,
     * not for something that keeps adding them.
     */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly TaskEligibilityEvents $eligibility,
        private readonly OutboundEvents $events,
        private readonly ActorContext $actors,
    ) {}

    public function handle(User $user): void
    {
        $workspaceIds = $this->workspaceIds($user);

        for ($attempt = 1; ; $attempt++) {
            $deleted = $this->actors->using(Actor::web($user), fn (): bool => $this->events->transaction($workspaceIds, function () use ($user, $workspaceIds): bool {
                /*
                 * Asked again once the locks are held. A workspace joined while
                 * this waited would otherwise be locked late, after the writes
                 * below — out of the order every other change takes. Nothing
                 * has been written yet, so returning commits nothing and lets
                 * go of the locks to start over with the full set.
                 */
                if (array_diff($this->workspaceIds($user), $workspaceIds) !== []) {
                    return false;
                }

                $this->delete($user);

                return true;
            }));

            if ($deleted) {
                return;
            }

            if ($attempt === self::ATTEMPTS) {
                throw new RuntimeException("Account {$user->id} kept joining workspaces while it was being deleted.");
            }

            $workspaceIds = array_values(array_unique([...$workspaceIds, ...$this->workspaceIds($user)]));
        }
    }

    private function delete(User $user): void
    {
        $assigned = fn (Project $project): Builder => Task::query()
            ->where('project_id', $project->id)
            ->where('assignee_id', $user->id);

        Project::query()
            ->whereHas('tasks', fn (Builder $tasks) => $tasks->where('assignee_id', $user->id))
            ->orderBy('id')
            ->get()
            ->each(fn (Project $project) => $this->eligibility->track(
                $project,
                EligibilityReason::MemberRemoved,
                fn (): Builder => $assigned($project),
                fn (?array $lockedIds): int => ($lockedIds === null ? $assigned($project) : Task::query()->whereKey($lockedIds))
                    ->update(['assignee_id' => null]),
            ));

        $this->releaseAuthorship($user);

        // Every membership, suspended ones included: deletion is final in
        // all of them.
        Workspace::query()
            ->whereIn('id', $user->workspaceMemberships()->select('workspace_id'))
            ->orderBy('id')
            ->get()
            ->each(fn (Workspace $workspace) => $this->events->record(
                OutboundEventType::WorkspaceMemberRemoved,
                $workspace,
                ['user_id' => $user->id, 'reason' => 'deleted'],
            ));

        $user->delete();
    }

    /**
     * Clear them as the reporter of tasks and the sender of messages before
     * the row goes, in the order other changes lock those rows — projects,
     * then tasks by id; messages by id. Left to the foreign keys
     * (`nullOnDelete`), the same updates would happen in whatever order the
     * cascade met the rows, holding the user row all the while.
     */
    private function releaseAuthorship(User $user): void
    {
        $reported = Task::query()->where('reporter_id', $user->id);

        Project::query()
            ->whereIn('id', (clone $reported)->select('project_id'))
            ->orderBy('id')
            ->lock('for no key update')
            ->pluck('id');

        $taskIds = (clone $reported)->orderBy('id')->lockForUpdate()->pluck('id');

        Task::query()->whereKey($taskIds)->update(['reporter_id' => null]);

        $messageIds = Message::withTrashed()->where('sender_id', $user->id)->orderBy('id')->lockForUpdate()->pluck('id');

        Message::withTrashed()->whereKey($messageIds)->update(['sender_id' => null]);
    }

    /**
     * Every workspace the deletion reports to, so all of their locks are taken
     * up front and in order — see OutboundEvents. One the person joins after
     * this read is locked when it is reached.
     *
     * @return array<int, int>
     */
    private function workspaceIds(User $user): array
    {
        return $user->workspaceMemberships()->pluck('workspace_id')
            ->merge(Project::query()
                ->whereHas('tasks', fn (Builder $tasks) => $tasks->where('assignee_id', $user->id))
                ->pluck('workspace_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
