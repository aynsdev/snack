<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts people on a project.
 *
 * # Every id is re-checked here
 *
 * The user ids arrive from a picker in the browser and are therefore untrusted.
 * Each one is narrowed to the *active members of this project's workspace*
 * before anything is written, so a hand-crafted request cannot add somebody from
 * another tenant — or a suspended member — onto a board and, from there, into
 * the assignee list.
 *
 * # Already a member
 *
 * Skipped rather than re-added, so this is idempotent: adding four people, two
 * of whom are already on, adds two. The unique index is what makes that true
 * under a double-submit; this is what stops it costing an exception.
 */
class AddProjectMembers
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<int, int>  $userIds
     * @return Collection<int, User> the people actually added
     */
    public function handle(
        Project $project,
        User $actor,
        array $userIds,
        ProjectRole $role = ProjectRole::Member,
    ): Collection {
        $existing = $project->members()->pluck('user_id')->all();

        /** @var Collection<int, User> $users */
        $users = $project->workspace->activeUsers()
            ->whereIn('users.id', $userIds)
            ->whereNotIn('users.id', $existing)
            ->get();

        if ($users->isEmpty()) {
            return $users;
        }

        DB::transaction(function () use ($project, $actor, $users, $role): void {
            foreach ($users as $user) {
                ProjectMember::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'joined_at' => now(),
                ]);

                $this->audit->handle(
                    workspace: $project->workspace,
                    action: AuditAction::ProjectMemberAdded,
                    actor: $actor,
                    auditable: $project,
                    newValues: [
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'role' => $role->value,
                    ],
                );
            }
        });

        return $users;
    }
}
