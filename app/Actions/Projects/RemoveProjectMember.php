<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\EligibilityReason;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Outbound\TaskEligibilityEvents;
use Illuminate\Database\Eloquent\Builder;

/**
 * Takes somebody off a project.
 *
 * # Their open tasks are unassigned
 *
 * This is the whole reason the removal is an action rather than a delete on the
 * pivot. An assignment is a claim that a named person is doing the work; once
 * they are off the project that claim is false, and a card still showing their
 * face is a card the team will keep waiting on. So the open ones are handed
 * back to the board, where somebody can pick them up.
 *
 * Completed tasks keep their assignee. Those are a record of who did the work,
 * and that does not stop being true when they move teams.
 */
class RemoveProjectMember
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly TaskEligibilityEvents $eligibility,
    ) {}

    /**
     * @return int how many open tasks were handed back
     */
    public function handle(Project $project, User $actor, User $member): int
    {
        $openTasks = fn (): Builder => Task::query()
            ->where('project_id', $project->id)
            ->where('assignee_id', $member->id)
            ->whereNull('completed_at');

        /*
         * One event per card handed back, rather than one for the removal: the
         * dispatcher works task by task, and an agent halfway through one of
         * these cards has to be told that it is no longer theirs. Only the cards
         * locked and reported on are unassigned, so none can slip through
         * between the two.
         */
        return $this->eligibility->track($project, EligibilityReason::MemberRemoved, $openTasks, function (?array $lockedIds) use ($project, $actor, $member, $openTasks): int {
            $unassigned = ($lockedIds === null ? $openTasks() : Task::query()->whereKey($lockedIds))
                ->update(['assignee_id' => null]);

            $project->members()->where('user_id', $member->id)->delete();

            $this->audit->handle(
                workspace: $project->workspace,
                action: AuditAction::ProjectMemberRemoved,
                actor: $actor,
                auditable: $project,
                oldValues: [
                    'user_id' => $member->id,
                    'username' => $member->username,
                ],
                newValues: ['unassigned_tasks' => $unassigned],
            );

            return $unassigned;
        });
    }
}
