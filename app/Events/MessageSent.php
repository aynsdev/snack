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
 * A message was posted to a channel or conversation.
 *
 * Deliberately *not* sent with `toOthers()`. The author's own other tabs and
 * devices are subscribers too, and they have no optimistic copy to reconcile
 * against — suppressing the author's broadcast would leave their phone silent
 * while they type on their laptop. The client instead de-duplicates by message
 * id, which is correct no matter how many sessions the author has open.
 */
class MessageSent implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    protected function message(): Message
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * The viewer-independent message shape.
     *
     * A broadcast reaches every subscriber at once, so it cannot carry the
     * per-viewer `can` flags an HTTP response does — there is no single viewer
     * to compute them for. The client derives its own affordance hints for
     * live-arriving messages, and every mutation is re-authorized server-side
     * regardless of what the client chose to render.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => MessageResource::broadcastPayload($this->message)];
    }
}
