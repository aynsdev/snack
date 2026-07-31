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
 * A message's reactions changed.
 *
 * Routed by {@see BroadcastsToContainer}, so a reaction on a thread reply
 * reaches the thread and one on a timeline message reaches the channel — the
 * same audience that can see the message being reacted to.
 *
 * # Why the whole summary
 *
 * The payload is the message's complete reaction state, not a "+1" delta. A
 * client that missed a single broadcast — a reconnect, a backgrounded tab —
 * would carry that drift until it reloaded. A full summary is self-correcting,
 * and it stays small: a handful of shortcodes and a capped id list.
 *
 * It carries no `reacted` flag, because one broadcast reaches every subscriber
 * and there is no single viewer to compute one for. Clients find their own id
 * in `user_ids`.
 */
class ReactionChanged implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    protected function message(): Message
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'reaction.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'reactions' => MessageResource::reactionSummary($this->message),
        ];
    }
}
