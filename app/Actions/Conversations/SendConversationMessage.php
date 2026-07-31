<?php

namespace App\Actions\Conversations;

use App\Actions\Messages\SendMessage;
use App\Events\ConversationActivity;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;

/**
 * Posts a message to a conversation.
 *
 * Wraps {@see SendMessage} — which does the actual writing and the timeline
 * broadcast — with the one thing a DM needs and a channel does not: telling the
 * *other* participants that something arrived.
 *
 * # Why the extra fan-out
 *
 * `MessageSent` goes to the conversation's presence channel, which reaches only
 * the people currently looking at that conversation. A DM has to reach someone
 * who is reading a different channel entirely, or their sidebar shows nothing
 * until they happen to navigate — which is the whole point of a direct message
 * failing.
 *
 * The payload names the conversation and nothing else. "Somebody messaged you
 * here" is information the recipient is entitled to; the message body is not
 * sent, so a stale subscription cannot become a content leak.
 */
class SendConversationMessage
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(
        Conversation $conversation,
        User $sender,
        string $body,
        ?Message $parent = null,
    ): Message {
        $message = $this->sendMessage->handle(
            container: $conversation,
            sender: $sender,
            body: $body,
            parent: $parent,
        );

        /*
         * A thread reply does not raise the DM.
         *
         * The sidebar badge counts what the conversation's timeline shows, and
         * the timeline excludes replies — so signalling here would badge a
         * conversation that looks unchanged when you open it, with no way to
         * clear it. Thread replies badge the thread instead, through the
         * subscription pointer.
         */
        if ($parent !== null) {
            return $message;
        }

        $recipients = $conversation->participants()
            ->active()
            // The sender's own devices already have the message: their timeline
            // receives the broadcast, and their sidebar has nothing to badge.
            ->where('user_id', '!=', $sender->id)
            ->get()
            ->map(fn (ConversationParticipant $participant): int => $participant->user_id)
            ->all();

        if ($recipients !== []) {
            ConversationActivity::dispatch($conversation->public_id, $recipients);
        }

        return $message;
    }
}
