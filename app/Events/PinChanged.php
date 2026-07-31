<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToContainer;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was pinned or unpinned.
 *
 * Goes to everyone in the container, because a pin is shared state — the pinned
 * bar at the top of the channel has to change for everybody at once, not on
 * their next reload.
 *
 * Carries the message itself, unlike {@see ThreadActivity}: the audience is
 * exactly the people who can already see it in the timeline, so the body is not
 * new information to any of them, and sending it means the bar can render the
 * new pin without a follow-up request.
 */
class PinChanged implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly bool $pinned,
    ) {}

    protected function message(): Message
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'pin.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pinned' => $this->pinned,
            'message' => MessageResource::broadcastPayload($this->message),
        ];
    }
}
