<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Actions\Conversations\UpdateConversationPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\UpdateConversationPreferencesRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Workspace;

/**
 * One participant's own notification settings for a conversation.
 */
class ConversationPreferenceController extends Controller
{
    /**
     * Answers with the conversation, whose `is_muted` and `notification_level`
     * are the viewer's own.
     */
    public function update(
        Workspace $workspace,
        Conversation $conversation,
        UpdateConversationPreferencesRequest $request,
        UpdateConversationPreferences $updatePreferences,
    ): ConversationResource {
        $updatePreferences->handle(
            conversation: $conversation,
            user: $request->user(),
            isMuted: $request->isMuted(),
            level: $request->notificationLevel(),
        );

        return new ConversationResource($conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $request->user()->id),
        ]));
    }
}
