<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody was removed from a channel by an administrator.
 *
 * Sent to the removed person's private user channel so every open tab can drop
 * the channel from its navigation, including a tab currently viewing it.
 */
class ChannelMembershipRevoked implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Channel $channel,
        public readonly int $removedUserId,
    ) {}

    /**
     * @return array<int, int>
     */
    protected function notifiableUserIds(): array
    {
        return [$this->removedUserId];
    }

    public function broadcastAs(): string
    {
        return 'channel.membership_revoked';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channel->id];
    }
}
