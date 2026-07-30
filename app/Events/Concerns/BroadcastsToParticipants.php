<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Fans an event out to a set of people's own private channels.
 *
 * Sidebar state — which conversations exist, which have something unread — is
 * per-person, and the people who need it are by definition *not* looking at the
 * conversation, so the conversation's presence channel cannot reach them. Their
 * own `user.{id}` channel can, and it is the one place a client is guaranteed to
 * be subscribed for as long as it is open.
 *
 * One event, several channels, rather than one event per recipient: the payload
 * is identical for everybody, and it deliberately carries no message content —
 * only the fact that a conversation they are in has changed.
 */
trait BroadcastsToParticipants
{
    /**
     * @return array<int, int>
     */
    abstract protected function notifiableUserIds(): array;

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId): PrivateChannel => new PrivateChannel('user.'.$userId),
            array_values(array_unique($this->notifiableUserIds())),
        );
    }
}
