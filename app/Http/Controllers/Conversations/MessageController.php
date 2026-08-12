<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SendConversationMessage;
use App\Actions\Standups\AnswerStandupFromReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\MessageHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversation message history and posting.
 *
 * These answer with JSON rather than Inertia responses, for the same reason the
 * channel endpoints do: a timeline is a live, client-owned list that merges
 * paginated history, WebSocket arrivals, and the user's own optimistic sends,
 * while Inertia props are server-owned and replaced wholesale on each visit.
 * Driving it through props would mean three writers racing on every keystroke.
 *
 * The first page still arrives as an Inertia prop from ConversationController@show,
 * so the conversation paints with history already in it.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageHistory $history) {}

    /**
     * A page of history, older than the given cursor.
     */
    public function index(Workspace $workspace, Conversation $conversation, Request $request): JsonResponse
    {
        $this->authorize('view', $conversation);

        $cursor = $request->integer('before') ?: null;

        return response()->json($this->history->page($conversation, $cursor));
    }

    public function store(
        Workspace $workspace,
        Conversation $conversation,
        StoreConversationMessageRequest $request,
        SendConversationMessage $sendMessage,
        AnswerStandupFromReply $answerStandup,
    ): JsonResponse {
        $message = $sendMessage->handle(
            conversation: $conversation,
            sender: $request->user(),
            body: $request->messageBody(),
            parent: $request->parentMessage(),
            attachments: $request->attachments(),
        );

        /*
         * A message in the standup bot's DM may be an answer to this morning's
         * standup, and is read as one here rather than inside the send path:
         * every DM in the workspace goes through SendMessage, and a feature that
         * inspects each of them from in there is how the message pipeline
         * becomes a place features are added to. This is a no-op — one indexed
         * lookup — for every conversation that is not a standup.
         *
         * After the send, never instead of it. The reply is the person's own
         * message first and an answer second: it is written, broadcast, and in
         * their timeline whatever the parser makes of it.
         */
        $answerStandup->handle($conversation, $request->user(), $message);

        return response()->json(
            ['message' => (new MessageResource($message))->resolve($request)],
            201,
        );
    }
}
