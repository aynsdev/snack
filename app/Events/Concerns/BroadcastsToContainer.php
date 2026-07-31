<?php

namespace App\Events\Concerns;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Routes a message event to the broadcast channel the message actually lives
 * on: its thread if it is a reply, otherwise its channel or conversation.
 *
 * Shared by every message event so the three of them cannot disagree about
 * where a message belongs — a mismatch would be invisible in testing (the event
 * fires, it just arrives nowhere) and would surface as "messages only appear
 * after a refresh".
 *
 * # Why a reply does not go to the container
 *
 * The container's timeline excludes replies (see Message::scopeRootLevel), so a
 * reply broadcast there would reach every member of the channel and be dropped
 * by all of them. Worse, it would put the reply's body on a channel whose
 * subscribers were deliberately not shown it. Replies go to `thread.{root}`,
 * which only people with that thread open subscribe to; the container hears
 * about it through {@see App\Events\ThreadActivity}, which carries counts and
 * no content.
 *
 * Containers are presence channels — they render "who is here" — while a thread
 * is private: it has no roster of its own, and its authorization callback in
 * routes/channels.php answers with a bool rather than a member payload.
 */
trait BroadcastsToContainer
{
    abstract protected function message(): Message;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $message = $this->message();

        if ($message->isThreadReply()) {
            return [new PrivateChannel('thread.'.$message->parent_message_id)];
        }

        $name = $message->isInChannel()
            ? 'channel.'.$message->channel_id
            : 'conversation.'.$message->conversation->public_id;

        return [new PresenceChannel($name)];
    }
}
