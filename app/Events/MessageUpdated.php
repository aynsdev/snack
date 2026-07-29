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
 * A message's body was edited by its author.
 *
 * Carries the whole message rather than a diff: the client replaces the row it
 * already holds, so it never has to reconstruct state it may have missed while
 * disconnected.
 */
class MessageUpdated implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    protected function message(): Message
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => MessageResource::broadcastPayload($this->message)];
    }
}
