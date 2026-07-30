<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user advanced their read position in a conversation.
 *
 * Broadcast to that one user's private channel so their other tabs and devices
 * clear the same badge. Nobody else is told what anyone has read: read position
 * is private state, not a read receipt shown to the other person. That matters
 * more in a DM than anywhere else — "seen at 11:04" is a social obligation
 * Snack deliberately does not create.
 */
class ConversationRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $conversationId,
        public readonly ?int $lastReadMessageId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
