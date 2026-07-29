<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Joining and leaving a channel under one's own steam.
 *
 * Adding *other* people is a different, admin-gated operation and lives on the
 * channel management screens.
 */
class ChannelMembershipController extends Controller
{
    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        JoinChannel $joinChannel,
    ): RedirectResponse {
        $this->authorize('join', $channel);

        $joinChannel->handle($channel, $request->user());

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

        return to_route('workspaces.show', ['workspace' => $workspace->slug])
            ->with('success', "You left #{$channel->slug}.");
    }
}
