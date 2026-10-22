<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Channels\MoveChannelGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\AssignChannelGroupRequest;
use App\Http\Requests\Channels\StoreChannelGroupRequest;
use App\Http\Requests\Channels\UpdateChannelGroupRequest;
use App\Http\Resources\ChannelGroupResource;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\ChannelMember;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The headings one member's channel list is arranged under — private to them,
 * and unaudited, for the reasons
 * {@see \App\Http\Controllers\Channels\ChannelGroupController} gives.
 */
class ChannelGroupController extends Controller
{
    public function store(Workspace $workspace, StoreChannelGroupRequest $request): JsonResponse
    {
        $group = ChannelGroup::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'name' => $request->string('name')->trim()->toString(),
            'position' => ChannelGroup::nextPosition($workspace, $request->user()),
        ]);

        // A section made *from* a channel files that channel under it in the
        // same request; the `channel_id` rule guarantees the membership exists.
        $channelId = $request->channelId();

        if ($channelId !== null) {
            ChannelMember::query()
                ->where('channel_id', $channelId)
                ->where('user_id', $request->user()->id)
                ->update(['channel_group_id' => $group->id]);
        }

        return (new ChannelGroupResource($group))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        ChannelGroup $channelGroup,
        UpdateChannelGroupRequest $request,
    ): ChannelGroupResource {
        $channelGroup->update([
            'name' => $request->string('name')->trim()->toString(),
        ]);

        return new ChannelGroupResource($channelGroup);
    }

    /**
     * One place up or down. Answers with the viewer's sections in their new
     * order, which is what the sidebar redraws from.
     */
    public function move(
        Workspace $workspace,
        ChannelGroup $channelGroup,
        Request $request,
        MoveChannelGroup $moveGroup,
    ): AnonymousResourceCollection {
        $this->authorize('update', $channelGroup);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        $moveGroup->handle($channelGroup, up: $validated['direction'] === 'up');

        return ChannelGroupResource::collection(
            ChannelGroup::query()->ownedBy($workspace, $request->user())->ordered()->get(),
        );
    }

    /**
     * Removes the heading only; its channels become ungrouped.
     */
    public function destroy(Workspace $workspace, ChannelGroup $channelGroup): Response
    {
        $this->authorize('delete', $channelGroup);

        $channelGroup->delete();

        return response()->noContent();
    }

    /**
     * File one channel under a heading (or none, with `channel_group_id: null`).
     */
    public function assign(
        Workspace $workspace,
        Channel $channel,
        AssignChannelGroupRequest $request,
    ): ChannelResource {
        $membership = $channel->membershipFor($request->user());

        if ($membership === null) {
            throw ValidationException::withMessages([
                'channel' => 'Join this channel before filing it in a section.',
            ]);
        }

        $membership->update(['channel_group_id' => $request->channelGroupId()]);

        return new ChannelResource($channel->setRelation('members', collect([$membership])));
    }
}
