<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToContainer;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A thread gained or lost a reply.
 *
 * Sent to the *container*, not the thread: the audience is everyone looking at
 * the channel, whose copy of the parent message shows "3 replies" and needs to
 * say four. People with the thread actually open get the reply itself over
 * `thread.{root}` instead.
 *
 * The payload is counts and identifiers only. It reaches every member of the
 * channel, including the ones who never open this thread, so putting the reply
 * body in it would broadcast thread content to an audience that is not reading
 * the thread — and would do it on the one channel a stale subscription is most
 * likely to still be attached to.
 *
 * The root is always a root: {@see BroadcastsToContainer} routes replies to the
 * thread channel, and dispatching this with a reply would send it to the wrong
 * place entirely.
 */
class ThreadActivity implements ShouldBroadcast
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $root) {}

    protected function message(): Message
    {
        return $this->root;
    }

    public function broadcastAs(): string
    {
        return 'thread.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'root_id' => $this->root->id,
            'reply_count' => $this->root->reply_count,
            'last_reply_at' => $this->root->last_reply_at?->toIso8601String(),
        ];
    }
}
