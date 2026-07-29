<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\MarkChannelRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\MarkChannelReadRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * The read pointer for a channel.
 *
 * Called as the user actually looks at the bottom of a channel, so it is
 * deliberately cheap: one indexed UPDATE against the membership row, and no
 * response body beyond the new pointer.
 */
class ChannelReadController extends Controller
{
    public function store(
        Workspace $workspace,
        Channel $channel,
        MarkChannelReadRequest $request,
        MarkChannelRead $markRead,
    ): JsonResponse {
        $membership = $markRead->handle(
            channel: $channel,
            user: $request->user(),
            messageId: $request->lastReadMessageId(),
        );

        return response()->json([
            'channel_id' => $channel->id,
            'last_read_message_id' => $membership?->last_read_message_id,
        ]);
    }
}
