<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Channels\SetChannelArchived;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Archiving and restoring a channel — two idempotent endpoints, for the reason
 * {@see \App\Http\Controllers\Channels\ChannelArchiveController} gives.
 *
 * Both answer with the channel, so the app can flip it read-only (or back)
 * without a second request.
 */
class ChannelArchiveController extends Controller
{
    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        SetChannelArchived $setArchived,
    ): ChannelResource {
        $this->authorize('archive', $channel);

        return $this->resource($setArchived->handle($channel, $request->user(), archived: true), $request);
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        SetChannelArchived $setArchived,
    ): ChannelResource {
        $this->authorize('archive', $channel);

        return $this->resource($setArchived->handle($channel, $request->user(), archived: false), $request);
    }

    private function resource(Channel $channel, Request $request): ChannelResource
    {
        $channel->load(['members' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new ChannelResource($channel);
    }
}
