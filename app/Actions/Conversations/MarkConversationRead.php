<?php

namespace App\Actions\Conversations;

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Notifications\MarkNotificationsRead;
use App\Events\ConversationRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Mention;
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
    public function __construct(private readonly MarkNotificationsRead $notifications) {}

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

        $this->clearMentions($conversation, $user, $target);

        /*
         * And the inbox rows. This is the case that made the badge look broken:
         * every message in a conversation raises one, and coalescing folds a
         * burst into a single row pointing at the newest of them — so reading
         * the conversation clears exactly that row, while a message arriving
         * after the pointer raises a new one.
         */
        $this->notifications->forContainer($conversation->workspace, $user, $conversation, $target);

        ConversationRead::dispatch($user->id, $conversation->public_id, $target);

        return $participant;
    }

    /**
     * Mark this reader's mentions in the conversation as read, up to the pointer.
     *
     * The counterpart of {@see MarkChannelRead::clearMentions()}, and it exists
     * for the same reason: a mention row that survives having read the message
     * it points at leaves the Mentions list highlighting something the reader
     * has already seen. Being named in a DM is no different from being named in
     * a channel — it writes the same row, and reading it has to clear the same
     * row.
     *
     * Bounded by the pointer, so a mention further down the conversation than
     * the reader has actually reached still counts.
     */
    private function clearMentions(Conversation $conversation, User $user, int $throughMessageId): void
    {
        Mention::query()
            ->where('mentioned_user_id', $user->id)
            ->whereNull('read_at')
            ->whereIn(
                'message_id',
                Message::query()
                    ->select('id')
                    ->where('conversation_id', $conversation->id)
                    ->where('id', '<=', $throughMessageId),
            )
            ->update(['read_at' => now()]);
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
