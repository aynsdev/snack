<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\UpdateConversationPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\UpdateConversationPreferencesRequest;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * One participant's own notification settings for a conversation.
 *
 * Separate from {@see ConversationController::update()} because it answers to a
 * different permission: renaming is a change to shared state that only an active
 * participant of a *group* may make, while muting is private and available to
 * anyone who can read the thread at all.
 */
class ConversationPreferenceController extends Controller
{
    public function update(
        Workspace $workspace,
        Conversation $conversation,
        UpdateConversationPreferencesRequest $request,
        UpdateConversationPreferences $updatePreferences,
    ): RedirectResponse {
        $participant = $updatePreferences->handle(
            conversation: $conversation,
            user: $request->user(),
            isMuted: $request->isMuted(),
            level: $request->notificationLevel(),
        );

        return back()->with(
            'success',
            $participant?->is_muted === true
                ? 'Conversation muted.'
                : 'Conversation unmuted.',
        );
    }
}
