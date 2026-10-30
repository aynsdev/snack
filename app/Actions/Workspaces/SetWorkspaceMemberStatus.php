<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\MemberStatus;
use App\Enums\OutboundEventType;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Outbound\OutboundEvents;

/**
 * Suspends or reinstates a member.
 *
 * Suspension is the reversible middle ground between doing nothing and removing
 * someone: history, channel memberships, and read state all survive, but every
 * access check treats them as a non-member until they are reinstated.
 */
class SetWorkspaceMemberStatus
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly OutboundEvents $events,
    ) {}

    public function handle(WorkspaceMember $member, MemberStatus $status, User $actor): WorkspaceMember
    {
        $this->events->transaction([$member->workspace_id], function () use ($member, $status, $actor): void {
            /*
             * The status as it stands now, read under the lock: a second
             * submission of the same change finds it already made, and records
             * and reports nothing.
             */
            $previousStatus = WorkspaceMember::query()->lockForUpdate()->find($member->id)?->status;

            if ($previousStatus === null || $previousStatus === $status) {
                return;
            }

            $member->update(['status' => $status]);

            $this->audit->handle(
                workspace: $member->workspace,
                action: $status === MemberStatus::Suspended
                    ? AuditAction::MemberSuspended
                    : AuditAction::MemberReinstated,
                actor: $actor,
                auditable: $member,
                oldValues: ['status' => $previousStatus->value],
                newValues: ['status' => $status->value],
            );

            // Suspended is "a non-member until reinstated" to every access
            // check, and so to the agent dispatcher too.
            if ($status === MemberStatus::Suspended && $previousStatus !== MemberStatus::Suspended) {
                $this->events->record(OutboundEventType::WorkspaceMemberRemoved, $member->workspace, [
                    'user_id' => $member->user_id,
                    'reason' => 'deactivated',
                ]);
            }
        });

        return $member->refresh();
    }
}
