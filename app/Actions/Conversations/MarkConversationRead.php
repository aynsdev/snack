<?php

namespace App\Actions\Conversations;

use App\Actions\Channels\MarkChannelRead;
use App\Events\ConversationRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;

/**
 * Advances a user's read pointer in a conversation.
 *
 * One row per participant, not one per message read: "unread" is derived by
 * counting messages with an id above the pointer, which stays a single index
 * range scan however long the history grows.
 *
 * Mirrors {@see MarkChannelRead} deliberately — the two
 * containers keep read state the same way, and a DM that tracked it differently
 * would need its own unread arithmetic to match.
 */
class MarkConversationRead
{
    /**
     * @param  int|null  $messageId  The newest message the client has displayed.
     *                               Null means "everything currently in the conversation".
     */
    public function handle(Conversation $conversation, User $user, ?int $messageId = null): ?ConversationParticipant
    {
        $participant = $conversation->participantFor($user);

        if ($participant === null) {
            return null;
        }

        $target = $messageId ?? $this->newestMessageId($conversation);

        if ($target === null) {
            return $participant;
        }

        /*
         * The pointer only ever moves forward. A tab left open on older history
         * will happily report a stale position when it finally syncs, and
         * honouring that would resurrect unread badges the user already cleared
         * elsewhere.
         */
        if ($participant->last_read_message_id !== null && $target <= $participant->last_read_message_id) {
            return $participant;
        }

        $participant->forceFill([
            'last_read_message_id' => $target,
            'last_read_at' => now(),
        ])->save();

        ConversationRead::dispatch($user->id, $conversation->public_id, $target);

        return $participant;
    }

    private function newestMessageId(Conversation $conversation): ?int
    {
        $newest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->rootLevel()
            ->max('id');

        return $newest === null ? null : (int) $newest;
    }
}
