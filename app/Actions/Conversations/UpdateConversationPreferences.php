<?php

namespace App\Actions\Conversations;

use App\Enums\NotificationLevel;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

/**
 * Changes one person's notification settings for a conversation.
 *
 * These live on the participant row, so muting is per-person: silencing a busy
 * group must not silence it for everyone else in it. Nothing here is broadcast —
 * a preference is private state, and the only client that needs to know is the
 * one that asked.
 */
class UpdateConversationPreferences
{
    public function handle(
        Conversation $conversation,
        User $user,
        ?bool $isMuted = null,
        ?NotificationLevel $level = null,
    ): ?ConversationParticipant {
        $participant = $conversation->participantFor($user);

        if ($participant === null) {
            return null;
        }

        $participant->forceFill([
            // Null means "leave this one alone", so a request that only mutes
            // does not silently reset the notification level as a side effect.
            'is_muted' => $isMuted ?? $participant->is_muted,
            'notification_level' => $level ?? $participant->notification_level,
        ])->save();

        $conversation->unsetRelation('participants');

        return $participant;
    }
}
