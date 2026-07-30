<?php

namespace App\Events\Concerns;

use App\Models\Message;
use Illuminate\Broadcasting\PresenceChannel;

/**
 * Routes a message event to the broadcast channel for whichever container the
 * message lives in.
 *
 * Shared by every message event so the three of them cannot disagree about
 * where a message belongs — a mismatch would be invisible in testing (the event
 * fires, it just arrives nowhere) and would surface as "messages only appear
 * after a refresh".
 *
 * Presence channels, matching the definitions in routes/channels.php, where
 * subscription is authorized against the container's own policy.
 */
trait BroadcastsToContainer
{
    abstract protected function message(): Message;

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        $message = $this->message();

        $name = $message->isInChannel()
            ? 'channel.'.$message->channel_id
            : 'conversation.'.$message->conversation->public_id;

        return [new PresenceChannel($name)];
    }
}
