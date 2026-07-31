<?php

namespace App\Http\Controllers\Messages;

use App\Actions\Messages\ToggleReaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\ReactionRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Reactions on a message.
 *
 * Two idempotent endpoints rather than one toggle: reactions are the control
 * people double-click, and a toggle turns two racing clicks into whichever
 * state arrived last. `store` and `destroy` each describe an end state, so
 * repeating one converges — see {@see ToggleReaction}.
 *
 * Both answer with the message's full reaction summary, so the client that made
 * the change reconciles against the same shape the broadcast delivers to
 * everyone else.
 */
class ReactionController extends Controller
{
    public function __construct(private readonly ToggleReaction $reactions) {}

    public function store(
        Workspace $workspace,
        Message $message,
        ReactionRequest $request,
    ): JsonResponse {
        $this->reactions->add($message, $request->user(), $request->emoji());

        return $this->summary($message);
    }

    public function destroy(
        Workspace $workspace,
        Message $message,
        ReactionRequest $request,
    ): JsonResponse {
        $this->reactions->remove($message, $request->user(), $request->emoji());

        return $this->summary($message);
    }

    private function summary(Message $message): JsonResponse
    {
        $message->load('reactions.user:id,name');

        return response()->json([
            'message_id' => $message->id,
            'reactions' => MessageResource::reactionSummary($message),
        ]);
    }
}
