<?php

namespace App\Events;

use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A conversation's own details changed: it was renamed, or its participant list
 * moved.
 *
 * Goes to the conversation's presence channel, which is exactly the set of
 * people currently looking at it — the ones whose header and participant list
 * are now wrong. Everyone else picks the change up from their navigation payload
 * the next time they open it.
 *
 * The payload is viewer-independent, so it deliberately omits the display name:
 * an unnamed conversation is titled by *the other* participants, which is a
 * different string for each of them. The client derives its own.
 */
class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Conversation $conversation) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('conversation.'.$this->conversation->public_id)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->conversation->loadMissing('activeUsers');

        return [
            'conversation' => [
                'id' => $this->conversation->public_id,
                'type' => $this->conversation->type->value,
                'name' => $this->conversation->name,
                'participants' => $this->conversation
                    ->activeUsers
                    ->map(fn (User $user): array => (new UserResource($user))->resolve())
                    ->all(),
            ],
        ];
    }
}
