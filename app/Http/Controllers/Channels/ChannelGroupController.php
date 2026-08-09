<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\MoveChannelGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\AssignChannelGroupRequest;
use App\Http\Requests\Channels\StoreChannelGroupRequest;
use App\Http\Requests\Channels\UpdateChannelGroupRequest;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\ChannelMember;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The headings one member's channel list is arranged under.
 *
 * A group has no page of its own — it is only ever a heading in the sidebar, and
 * the sidebar rides on every workspace page — so every action here answers with
 * `back()` and the shell re-renders with the new arrangement.
 *
 * Nothing here is audited, which is the one deliberate difference from
 * {@see App\Http\Controllers\Projects\ProjectGroupController}. The audit log is
 * a record of what people did to the workspace; naming a section of your own
 * sidebar changes nothing anyone else can see, and logging it would put a line
 * in the administrators' feed every time somebody tidied their channel list.
 */
class ChannelGroupController extends Controller
{
    public function store(Workspace $workspace, StoreChannelGroupRequest $request): RedirectResponse
    {
        $group = ChannelGroup::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'name' => $request->string('name')->trim()->toString(),
            'position' => ChannelGroup::nextPosition($workspace, $request->user()),
        ]);

        /*
         * A section made *from* a channel is filed with it in the same request
         * — see the `channel_id` rule for why the two halves are one call. The
         * membership is guaranteed to exist by that rule; `update()` on a
         * missing row would be a silent no-op rather than a wrong write.
         */
        $channelId = $request->channelId();

        if ($channelId !== null) {
            ChannelMember::query()
                ->where('channel_id', $channelId)
                ->where('user_id', $request->user()->id)
                ->update(['channel_group_id' => $group->id]);
        }

        return back()->with('success', "{$group->name} added.");
    }

    public function update(
        Workspace $workspace,
        ChannelGroup $channelGroup,
        UpdateChannelGroupRequest $request,
    ): RedirectResponse {
        $channelGroup->update([
            'name' => $request->string('name')->trim()->toString(),
        ]);

        return back()->with('success', 'Section renamed.');
    }

    /**
     * One place up, or one place down.
     *
     * A dedicated endpoint rather than a `position` field on the update above:
     * the client says which way, and the server works out what that means
     * against the list as it is right now.
     */
    public function move(
        Workspace $workspace,
        ChannelGroup $channelGroup,
        Request $request,
        MoveChannelGroup $moveGroup,
    ): RedirectResponse {
        $this->authorize('update', $channelGroup);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        $moveGroup->handle($channelGroup, up: $validated['direction'] === 'up');

        return back();
    }

    /**
     * Removing a heading, not the channels under it.
     *
     * They become ungrouped and are still in the sidebar — see the
     * `nullOnDelete` in the channel_groups migration. Nobody leaves a channel by
     * deleting a section, which is why this needs no confirmation beyond the one
     * the client shows.
     */
    public function destroy(
        Workspace $workspace,
        ChannelGroup $channelGroup,
    ): RedirectResponse {
        $this->authorize('delete', $channelGroup);

        $name = $channelGroup->name;
        $channelGroup->delete();

        return back()->with('success', "{$name} deleted.");
    }

    /**
     * Filing one channel under one heading, or under none.
     *
     * On the membership row rather than the channel: the arrangement is the
     * viewer's, so this writes the same row that holds their mute flag and read
     * pointer. Someone who has not joined has no such row and so has nothing to
     * arrange — the channel is not in their sidebar in the first place.
     */
    public function assign(
        Workspace $workspace,
        Channel $channel,
        AssignChannelGroupRequest $request,
    ): RedirectResponse {
        $membership = $channel->membershipFor($request->user());

        if ($membership === null) {
            return back()->with('error', 'Join this channel before filing it in a section.');
        }

        $membership->update(['channel_group_id' => $request->channelGroupId()]);

        return back();
    }
}
