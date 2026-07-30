<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone was brought into a conversation — a new DM, a new group, or being
 * added to an existing one.
 *
 * Sent to the new participants only. The payload is just the conversation's id:
 * how that conversation is *titled* depends on who is looking at it, and one
 * broadcast reaches everybody at once, so the shape cannot be viewer-specific.
 * Clients respond by re-reading their own navigation payload.
 */
class ConversationStarted implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $participantIds
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $participantIds,
    ) {}

    /**
     * @return array<int, int>
     */
    protected function notifiableUserIds(): array
    {
        return $this->participantIds;
    }

    public function broadcastAs(): string
    {
        return 'conversation.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->public_id];
    }
}
