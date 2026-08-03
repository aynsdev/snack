<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message arrived in a channel, addressed to the members who are not
 * currently looking at it.
 *
 * The exact counterpart of {@see ConversationActivity}, and it exists for the
 * same reason: `MessageSent` goes to the channel's presence channel, which only
 * reaches people who already have that channel open. Without this, a message in
 * #design while you are reading #general produced nothing at all — no badge, no
 * sound, no banner — until you happened to reload or navigate. Direct messages
 * had this from the start; channels never did.
 *
 * # Why the members' own channels rather than the workspace's
 *
 * One broadcast to `workspace.{id}` would be cheaper — a single event however
 * many members a channel has — but every member of the workspace would receive
 * it, including for channels they cannot see. The payload carries no content,
 * so nothing said would leak, but the *timing* of activity in a private channel
 * would, to anyone willing to read their own socket. Fanning out to the members'
 * own `user.{id}` channels keeps the audience exactly the set of people already
 * entitled to know, which is the same trade the conversation event makes.
 *
 * The cost is a channel name per member on one publish. Worth knowing before
 * raising `snack.workspaces.member_limit` much beyond its current 500.
 */
class ChannelActivity implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(
        public readonly int $channelId,
        public readonly array $recipientIds,
    ) {}

    /**
     * @return array<int, int>
     */
    protected function notifiableUserIds(): array
    {
        return $this->recipientIds;
    }

    public function broadcastAs(): string
    {
        return 'channel.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         * The channel id and nothing else. The client re-reads its unread counts
         * from the server rather than trusting a number off the wire, and the
         * body stays off a broadcast that fans out to people the server has not
         * individually authorized to read it.
         */
        return ['channel_id' => $this->channelId];
    }
}
