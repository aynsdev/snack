<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\MemberStatus;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Suspends or reinstates a member.
 *
 * Suspension is the reversible middle ground between doing nothing and removing
 * someone: history, channel memberships, and read state all survive, but every
 * access check treats them as a non-member until they are reinstated.
 */
class SetWorkspaceMemberStatus
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(WorkspaceMember $member, MemberStatus $status, User $actor): WorkspaceMember
    {
        $previousStatus = $member->status;

        DB::transaction(function () use ($member, $status, $actor, $previousStatus): void {
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
        });

        return $member->refresh();
    }
}
