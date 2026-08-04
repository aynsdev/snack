<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody was added to a channel by somebody else.
 *
 * Sent to the people who were added, and to nobody else. {@see ChannelCreated}
 * cannot do this job: it announces a channel to the whole workspace, which is
 * exactly what a private channel must never do, and it only fires once — at
 * creation — so a private channel gaining its fifth member reached no client at
 * all until that client happened to reload.
 *
 * The payload is the channel's id and nothing more. Whether the recipient sees
 * it as unread, muted, or top of their sidebar comes from queries only the
 * server can run, so the client responds by re-reading its navigation payload —
 * the same contract {@see ChannelActivity} and {@see ConversationStarted} keep.
 */
class ChannelMembershipGranted implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $addedUserIds
     */
    public function __construct(
        public readonly Channel $channel,
        public readonly array $addedUserIds,
    ) {}

    /**
     * @return array<int, int>
     */
    protected function notifiableUserIds(): array
    {
        return $this->addedUserIds;
    }

    public function broadcastAs(): string
    {
        return 'channel.membership_granted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channel->id];
    }
}
