<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToContainer;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was removed, by its author or by a workspace admin moderating.
 *
 * The payload is only the identifiers needed to drop the row. Re-broadcasting
 * the body of a message that was just deleted would hand it to every subscriber
 * at the moment someone decided it should not be there — including people who
 * had not yet loaded that part of the history.
 */
class MessageDeleted implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    protected function message(): Message
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'channel_id' => $this->message->channel_id,
            'conversation_id' => $this->message->conversation_id,
            'parent_message_id' => $this->message->parent_message_id,
        ];
    }
}
