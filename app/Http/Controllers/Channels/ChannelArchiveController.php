<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SetChannelArchived;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Archiving and restoring a channel.
 *
 * Two idempotent endpoints rather than one toggle, for the reason the reaction
 * controllers give: a toggle turns a duplicate submission into the opposite
 * action, and here that means a double-clicked "Archive" quietly restores the
 * channel it was meant to close.
 *
 * Gated on ChannelPolicy::archive, which is `update` plus a refusal for default
 * channels — a workspace whose #general has been archived has no landing
 * channel for anyone joining it.
 */
class ChannelArchiveController extends Controller
{
    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        SetChannelArchived $setArchived,
    ): RedirectResponse {
        $this->authorize('archive', $channel);

        $setArchived->handle($channel, $request->user(), archived: true);

        /*
         * Away from the channel, not back to it: it has just left the sidebar
         * and the policy now refuses posts to it, so returning would leave the
         * person looking at a page whose every control is disabled.
         */
        return to_route('channels.index', ['workspace' => $workspace->slug])
            ->with('success', "#{$channel->slug} archived. Its history is still searchable.");
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        SetChannelArchived $setArchived,
    ): RedirectResponse {
        // `archive` covers both directions — see the policy for why it does not
        // route through `update`, which refuses archived channels outright.
        $this->authorize('archive', $channel);

        $setArchived->handle($channel, $request->user(), archived: false);

        return to_route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ])->with('success', "#{$channel->slug} restored.");
    }
}
