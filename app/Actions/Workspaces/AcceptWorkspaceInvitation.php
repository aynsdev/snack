<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\JoinChannel;
use App\Enums\AuditAction;
use App\Enums\MemberStatus;
use App\Events\WorkspaceMemberJoined;
use App\Models\Channel;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Redeems an invitation: creates the membership and joins the workspace's
 * default channels.
 *
 * The invitation is re-checked inside the transaction and marked accepted with
 * a conditional UPDATE, so two simultaneous clicks on the same link cannot
 * produce two memberships.
 */
class AcceptWorkspaceInvitation
{
    public function __construct(
        private readonly JoinChannel $joinChannel,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @throws ValidationException when the invitation is expired, revoked, or
     *                             already redeemed.
     */
    public function handle(WorkspaceInvitation $invitation, User $user): WorkspaceMember
    {
        $membership = DB::transaction(function () use ($invitation, $user): WorkspaceMember {
            // Claim the invitation first. The WHERE clause is the concurrency
            // guard: only one request can transition it from outstanding to
            // accepted, and the loser sees zero affected rows.
            $claimed = WorkspaceInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'accepted_at' => now(),
                    'accepted_by' => $user->id,
                ]);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation is no longer valid.',
                ]);
            }

            $workspace = $invitation->workspace;

            if ($workspace->hasReachedMemberLimit()) {
                throw ValidationException::withMessages([
                    'token' => 'This workspace has reached its member limit.',
                ]);
            }

            $membership = WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);

            // Guests are deliberately not auto-joined to anything: an admin
            // must add them to the specific channels they are here for.
            if (! $invitation->role->isGuest()) {
                $workspace->channels()
                    ->where('is_default', true)
                    ->where('is_archived', false)
                    ->get()
                    ->each(fn (Channel $channel) => $this->joinChannel->handle($channel, $user));
            }

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::MemberJoined,
                actor: $user,
                auditable: $membership,
                newValues: ['role' => $invitation->role->value, 'via' => 'invitation'],
            );

            return $membership;
        });

        $user->unsetRelation('workspaceMemberships');

        WorkspaceMemberJoined::dispatch($membership);

        return $membership;
    }
}
