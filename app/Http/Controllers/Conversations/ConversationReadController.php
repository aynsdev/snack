<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\MarkConversationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\MarkConversationReadRequest;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * The read pointer for a conversation.
 *
 * Called as the user actually looks at the bottom of a conversation, so it is
 * deliberately cheap: one indexed UPDATE against the participant row, and no
 * response body beyond the new pointer.
 */
class ConversationReadController extends Controller
{
    public function store(
        Workspace $workspace,
        Conversation $conversation,
        MarkConversationReadRequest $request,
        MarkConversationRead $markRead,
    ): JsonResponse {
        $participant = $markRead->handle(
            conversation: $conversation,
            user: $request->user(),
            messageId: $request->lastReadMessageId(),
        );

        return response()->json([
            'conversation_id' => $conversation->public_id,
            'last_read_message_id' => $participant?->last_read_message_id,
        ]);
    }
}
