<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Outbound\TaskEligibilityEvents;
use Illuminate\Validation\ValidationException;

/**
 * Changes a member's workspace role.
 *
 * Ownership is not assignable here: there is exactly one owner, and moving it
 * is a separate action with its own confirmation flow.
 *
 * A guest sees only the channels they were added to, so becoming one — or
 * ceasing to be one — can change whether a person sees the agent channel their
 * tasks are worked in; the agent dispatcher is told — see
 * {@see TaskEligibilityEvents::aroundViewerChange()}.
 */
class UpdateWorkspaceMemberRole
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly TaskEligibilityEvents $eligibility,
    ) {}

    /**
     * @throws ValidationException when asked to assign the Owner role.
     */
    public function handle(WorkspaceMember $member, WorkspaceRole $role, User $actor): WorkspaceMember
    {
        if ($role === WorkspaceRole::Owner) {
            throw ValidationException::withMessages([
                'role' => 'Use ownership transfer to change the workspace owner.',
            ]);
        }

        $previousRole = $member->role;

        $this->eligibility->aroundViewerChange($member->workspace_id, null, [$member->user_id], function () use ($member, $role, $actor, $previousRole): void {
            $member->update(['role' => $role]);

            $this->audit->handle(
                workspace: $member->workspace,
                action: AuditAction::MemberRoleChanged,
                actor: $actor,
                auditable: $member,
                oldValues: ['role' => $previousRole->value],
                newValues: ['role' => $role->value],
            );
        }, fn (): bool => WorkspaceMember::query()->find($member->id)?->role->isGuest() !== $role->isGuest());

        return $member->refresh();
    }
}
