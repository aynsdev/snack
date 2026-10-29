<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToParticipants;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A huddle started or ended somewhere the recipient is a member, addressed to
 * the people who are not currently looking at that container.
 *
 * The exact counterpart of {@see ChannelActivity}, and it exists for the same
 * reason: {@see HuddleChanged} goes to the container's presence channel, which
 * only reaches people who already have that channel open. Without this, a huddle
 * starting in #design while you are reading #general is completely invisible —
 * and a huddle nobody is told about is a huddle nobody joins, which is the whole
 * feature failing quietly.
 *
 * # Only the two ends, not every join
 *
 * `HuddleChanged` fires on every roster change; this deliberately does not. What
 * a person somewhere else in the workspace needs to know is that a call is
 * happening in a channel of theirs, not that a fourth person walked into it. The
 * sidebar renders a live dot, and the dot has only two states.
 *
 * # Why the members' own channels rather than the workspace's
 *
 * Identical to the reasoning on {@see ChannelActivity}: one broadcast to
 * `workspace.{id}` would be cheaper, but it would tell every member of the
 * workspace that a call is under way in a private channel they cannot see. The
 * payload carries no content, but the timing of activity in a private channel is
 * itself the thing being protected. Fanning out to `user.{id}` keeps the
 * audience to people already entitled to know.
 */
class HuddleActivity implements ShouldBroadcast
{
    use BroadcastsToParticipants, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{kind: string, id: int|string|null}  $container  Where the
     *                                                               huddle is, in the same shape HuddleResource uses.
     * @param  array<int, int>  $recipientIds
     * @param  array{id: int, name: string, avatar_url: string|null}|null  $startedBy  Who
     *                                                                                 started it, while it is live; null once it has ended.
     */
    public function __construct(
        public readonly string $huddleId,
        public readonly array $container,
        public readonly bool $isLive,
        public readonly array $recipientIds,
        public readonly ?array $startedBy = null,
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
        return 'huddle.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         * Where, and whether it is still going. No roster: the recipients are by
         * definition not looking at that container, and a sidebar dot does not
         * need names. Anyone who navigates there gets the full state from the
         * page itself and from HuddleChanged thereafter.
         *
         * The one name it does carry is the caller's. The mobile app rings on a
         * huddle starting in a conversation, and an incoming-call screen that
         * cannot say who is calling is one nobody answers — fetching it would be
         * a round trip between the ring and the face, on the one screen where a
         * second matters. Null whenever `is_live` is false: an ending is not a
         * call, and nobody "started" the silence.
         */
        return [
            'huddle_id' => $this->huddleId,
            'container' => $this->container,
            'is_live' => $this->isLive,
            'started_by' => $this->startedBy,
        ];
    }
}
