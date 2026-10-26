<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\OutboundEventType;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Outbound\OutboundEvents;

/**
 * Removes a member from a workspace.
 *
 * Their messages stay: deleting a departing colleague's side of every
 * conversation would destroy context for everyone who remains. Their channel
 * memberships and read state are dropped along with the membership row, via
 * the cascading foreign keys.
 *
 * The agent dispatcher is told, so it stops sending their agent anything from
 * a workspace they can no longer see.
 */
class RemoveWorkspaceMember
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly OutboundEvents $events,
    ) {}

    public function handle(WorkspaceMember $member, User $actor): void
    {
        $this->events->transaction([$member->workspace_id], function () use ($member, $actor): void {
            // Read under the lock, so removing somebody already removed — a
            // double submit — records and reports nothing.
            if (WorkspaceMember::query()->lockForUpdate()->find($member->id) === null) {
                return;
            }

            $workspace = $member->workspace;
            $user = $member->user;

            $workspace->channels()
                ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
                ->each(fn ($channel) => $channel->members()->where('user_id', $user->id)->delete());

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::MemberRemoved,
                actor: $actor,
                auditable: $member,
                oldValues: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $member->role->value,
                ],
            );

            $member->delete();

            $this->events->record(OutboundEventType::WorkspaceMemberRemoved, $workspace, [
                'user_id' => $user->id,
                'reason' => 'removed',
            ]);
        });
    }
}
