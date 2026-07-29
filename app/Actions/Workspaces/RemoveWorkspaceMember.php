<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member from a workspace.
 *
 * Their messages stay: deleting a departing colleague's side of every
 * conversation would destroy context for everyone who remains. Their channel
 * memberships and read state are dropped along with the membership row, via
 * the cascading foreign keys.
 */
class RemoveWorkspaceMember
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(WorkspaceMember $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor): void {
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
        });
    }
}
