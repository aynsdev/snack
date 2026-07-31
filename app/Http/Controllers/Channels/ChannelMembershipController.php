<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Joining and leaving a channel under one's own steam.
 *
 * Adding *other* people is a different, admin-gated operation and lives on the
 * channel management screens.
 *
 * # Why the audit entries are written here and not in the actions
 *
 * {@see JoinChannel} is also the enrolment path for the automatic joins — the
 * default channels somebody lands in when they accept an invitation. Recording
 * from inside it would put a `channel.member_added` entry against every default
 * channel for every person who ever joins the workspace, which is already said
 * once by `member.joined` and would bury the deliberate changes underneath it.
 *
 * These two endpoints are the deliberate ones, so this is where the trail is
 * written.
 */
class ChannelMembershipController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        JoinChannel $joinChannel,
    ): RedirectResponse {
        $this->authorize('join', $channel);

        $joinChannel->handle($channel, $request->user());

        $this->record($workspace, $channel, $request->user(), AuditAction::ChannelMemberAdded);

        return to_route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ])->with('success', "You joined #{$channel->slug}.");
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        LeaveChannel $leaveChannel,
    ): RedirectResponse {
        $this->authorize('leave', $channel);

        $leaveChannel->handle($channel, $request->user());

        $this->record($workspace, $channel, $request->user(), AuditAction::ChannelMemberRemoved);

        return to_route('workspaces.show', ['workspace' => $workspace->slug])
            ->with('success', "You left #{$channel->slug}.");
    }

    /**
     * The entry is hung off the channel rather than the person, because that is
     * how it will be read back: "who has been in and out of #finance?".
     */
    private function record(
        Workspace $workspace,
        Channel $channel,
        User $user,
        AuditAction $action,
    ): void {
        $this->audit->handle(
            workspace: $workspace,
            action: $action,
            actor: $user,
            auditable: $channel,
            newValues: [
                'channel' => $channel->slug,
                'user_id' => $user->id,
                'username' => $user->username,
            ],
        );
    }
}
