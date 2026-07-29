<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Messages\SendMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\MessageHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Channel message history and posting.
 *
 * These endpoints answer with JSON rather than Inertia responses. A channel's
 * timeline is a live, client-owned list — it merges paginated history, messages
 * arriving over the WebSocket, and the user's own optimistic sends — and Inertia
 * props are server-owned and replaced wholesale on each visit. Driving the
 * timeline through props would mean two sources of truth racing each other on
 * every keystroke and every broadcast.
 *
 * The first page still arrives as an Inertia prop from ChannelController@show,
 * so the channel paints with history already in it.
 *
 * Every action declares {workspace} first, matching the route's parameter order.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageHistory $history) {}

    /**
     * A page of history, older than the given cursor.
     */
    public function index(Workspace $workspace, Channel $channel, Request $request): JsonResponse
    {
        $this->authorize('view', $channel);

        $cursor = $request->integer('before') ?: null;

        return response()->json($this->history->page($channel, $cursor));
    }

    public function store(
        Workspace $workspace,
        Channel $channel,
        StoreMessageRequest $request,
        SendMessage $sendMessage,
    ): JsonResponse {
        $message = $sendMessage->handle(
            channel: $channel,
            sender: $request->user(),
            body: $request->messageBody(),
        );

        return response()->json(
            ['message' => $this->resource($message, $request)],
            201,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Message $message, Request $request): array
    {
        return (new MessageResource($message))->resolve($request);
    }
}
