<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\AddChannelMembers;
use App\Actions\Channels\RemoveChannelMember;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelMemberRequest;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adding other people to a channel and removing them again.
 *
 * The admin-gated mirror of {@see ChannelMembershipController}, which is a
 * person enrolling themselves. They are deliberately separate endpoints rather
 * than one that branches on whose id is in the body: the two answer to different
 * policies — `join` versus `manageMembers` — and folding them together would
 * make the authorization depend on request data, which is the shape of mistake
 * that ends with someone adding themselves to a private channel.
 *
 * The audit trail is written here, not in the action, for the reason
 * {@see ChannelMembershipController} sets out: the action is also the enrolment
 * path for automatic joins, and recording from inside it would bury the
 * deliberate changes under an entry for every default channel every new hire
 * lands in.
 */
class ChannelMemberController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(
        Workspace $workspace,
        Channel $channel,
        StoreChannelMemberRequest $request,
        AddChannelMembers $addMembers,
    ): RedirectResponse {
        $added = $addMembers->handle(
            channel: $channel,
            actor: $request->user(),
            users: $request->nominees(),
        );

        if ($added->isEmpty()) {
            return back()->with('error', "Everyone you chose is already in #{$channel->slug}.");
        }

        foreach ($added as $user) {
            $this->record($workspace, $channel, $request->user(), $user);
        }

        return back()->with('success', sprintf(
            'Added %s to #%s.',
            $added->map(fn (User $user): string => $user->name)->join(', ', ' and '),
            $channel->slug,
        ));
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        ChannelMember $member,
        Request $request,
        RemoveChannelMember $removeMember,
    ): RedirectResponse {
        $this->authorize('manageMembers', $channel);

        if ($channel->is_default) {
            return back()->with('error', 'Members cannot be removed from the default channel.');
        }

        if ($member->user_id === $request->user()->id) {
            return back()->with('error', 'Use Leave channel to remove yourself.');
        }

        $subject = $member->user;

        $removeMember->handle($channel, $request->user(), $subject);
        $this->recordRemoval($workspace, $channel, $request->user(), $subject);

        return back()->with('success', "{$subject->name} removed from #{$channel->slug}.");
    }

    /**
     * One entry per person added, hung off the channel rather than the person,
     * because that is how it will be read back: "who has been in and out of
     * #finance?".
     *
     * The actor and the subject are separate fields here — unlike a self-serve
     * join, where they are the same user — so the trail can answer "who let them
     * in?" and not only "who is in?".
     */
    private function record(
        Workspace $workspace,
        Channel $channel,
        User $actor,
        User $subject,
    ): void {
        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ChannelMemberAdded,
            actor: $actor,
            auditable: $channel,
            newValues: [
                'channel' => $channel->slug,
                'user_id' => $subject->id,
                'username' => $subject->username,
                'added_by' => $actor->username,
            ],
        );
    }

    private function recordRemoval(
        Workspace $workspace,
        Channel $channel,
        User $actor,
        User $subject,
    ): void {
        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ChannelMemberRemoved,
            actor: $actor,
            auditable: $channel,
            oldValues: [
                'channel' => $channel->slug,
                'user_id' => $subject->id,
                'username' => $subject->username,
                'removed_by' => $actor->username,
            ],
        );
    }
}
