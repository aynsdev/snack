<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message arrived in a conversation, addressed to the participants who are not
 * currently looking at it.
 *
 * This is what makes a DM badge appear while the recipient is reading something
 * else. `MessageSent` cannot do it: that goes to the conversation's presence
 * channel, which only reaches people who already have it open.
 *
 * The payload names the conversation and stops there. "Someone messaged you
 * here" is information the recipient is entitled to; the body is not included,
 * so this can never become a content leak, and the client re-reads its unread
 * counts from the server rather than trusting a number off the wire.
 */
class ConversationActivity implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $recipientIds,
    ) {}

    /**
     * @return array<int, int>
     */
    protected function notifiableUserIds(): array
    {
        return $this->recipientIds;
    }

    public function broadcastAs(): string
    {
        return 'conversation.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
