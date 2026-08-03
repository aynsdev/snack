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
            // Claim the invitation first, so the concurrency guard runs before
            // any membership is written.
            $claimed = $invitation->isLink()
                ? $this->claimLink($invitation)
                : $this->claimAddressed($invitation, $user);

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

    /**
     * Spend a single-use, emailed invitation.
     *
     * The WHERE clause is the concurrency guard: only one request can move it
     * from outstanding to accepted, and the loser sees zero affected rows.
     */
    private function claimAddressed(WorkspaceInvitation $invitation, User $user): int
    {
        return WorkspaceInvitation::query()
            ->whereKey($invitation->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ]);
    }

    /**
     * Spend one redemption of a shareable link.
     *
     * Deliberately does *not* stamp `accepted_at`: that column is what makes an
     * invitation single-use, and setting it here would burn the whole link on
     * the first person through it — which is the one thing a link exists not to
     * do. `accepted_by` is left alone for the same reason; a link has many
     * acceptors, and the workspace's audit log is where they are recorded.
     *
     * The increment is written as a column expression rather than a read
     * followed by a write, so two people clicking the same link at the same
     * moment cannot both see `use_count` at 4 and both be let in past a limit
     * of 5. The `max_uses` comparison lives in the same statement for exactly
     * that reason — checking it in PHP first would reopen the race it closes.
     */
    private function claimLink(WorkspaceInvitation $invitation): int
    {
        return WorkspaceInvitation::query()
            ->whereKey($invitation->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn ($query) => $query
                ->whereNull('max_uses')
                ->orWhereColumn('use_count', '<', 'max_uses'))
            ->increment('use_count');
    }
}
