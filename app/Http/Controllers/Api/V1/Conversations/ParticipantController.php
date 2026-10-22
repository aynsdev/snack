<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Actions\Conversations\AddConversationParticipants;
use App\Actions\Conversations\LeaveConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationParticipantRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Who is in a group conversation, for the mobile app.
 *
 * Neither action is available on a direct message — the policy refuses both.
 */
class ParticipantController extends Controller
{
    /**
     * Add people. Anyone already in is skipped rather than refused, so `added`
     * may be empty — the web app's "everyone you chose is already here".
     */
    public function store(
        Workspace $workspace,
        Conversation $conversation,
        StoreConversationParticipantRequest $request,
        AddConversationParticipants $addParticipants,
    ): JsonResponse {
        $user = $request->user();

        $added = $addParticipants->handle(
            conversation: $conversation,
            actor: $user,
            users: $request->participants(),
        );

        $conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        return response()->json([
            'data' => [
                'added' => $added
                    ->map(fn (User $person): array => (new UserResource($person))->resolve($request))
                    ->values()
                    ->all(),
                'conversation' => (new ConversationResource($conversation))->resolve($request),
            ],
        ]);
    }

    /**
     * Leave the conversation. The leaver keeps read access to the history.
     */
    public function destroy(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        LeaveConversation $leave,
    ): Response {
        $this->authorize('leave', $conversation);

        $leave->handle($conversation, $request->user());

        return response()->noContent();
    }
}
