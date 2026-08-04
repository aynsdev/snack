<?php

namespace App\Actions\Huddles;

use App\Events\HuddleActivity;
use App\Events\HuddleChanged;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Huddle;
use App\Models\User;

/**
 * Tells everybody who should know that a huddle changed.
 *
 * Extracted from the three actions that cause the changes, because every one of
 * them has to answer the same awkward question — *which* people — and the answer
 * differs by container type. Getting it wrong in one of three places would be
 * invisible in testing: the events fire, they simply reach the wrong audience.
 *
 * Two audiences, deliberately distinct:
 *
 *  - Everyone with the container open hears {@see HuddleChanged} on its presence
 *    channel. That is the huddle bar, and it moves on every join and leave.
 *  - Everyone who is a *member* of the container hears {@see HuddleActivity} on
 *    their own private channel, but only when a huddle starts or ends. That is
 *    the sidebar dot, and it would be noise on every roster change.
 */
class AnnounceHuddle
{
    /**
     * The roster moved: somebody joined or left, and the huddle is otherwise as
     * it was.
     */
    public function roster(Huddle $huddle): void
    {
        HuddleChanged::dispatch($huddle);
    }

    /**
     * The huddle started or ended.
     *
     * @param  User|null  $except  Somebody to leave out of the sidebar fan-out —
     *                             the person who just started it, whose own client already knows.
     *                             Never applied to the presence broadcast, which their other tabs
     *                             legitimately need.
     */
    public function lifecycle(Huddle $huddle, ?User $except = null): void
    {
        $this->roster($huddle);

        $recipients = $this->containerMemberIds($huddle, $except);

        if ($recipients === []) {
            return;
        }

        HuddleActivity::dispatch(
            $huddle->public_id,
            $huddle->isInChannel()
                ? ['kind' => 'channel', 'id' => $huddle->channel_id]
                : ['kind' => 'conversation', 'id' => $huddle->conversation?->public_id],
            $huddle->isLive(),
            $recipients,
        );
    }

    /**
     * Everyone entitled to know a huddle is happening in this container.
     *
     * Channel members rather than everyone who *could* see the channel: a public
     * channel is visible to the whole workspace, and badging a call in a channel
     * somebody has never joined is noise from a room they are not in. Joining
     * the channel is what opts you into hearing about it.
     *
     * Conversation participants exclude those who left, which the `left_at`
     * filter handles — a former participant keeps read access to the history and
     * has no business being told about live calls in it.
     *
     * @return array<int, int>
     */
    private function containerMemberIds(Huddle $huddle, ?User $except): array
    {
        $container = $huddle->container();

        $query = match (true) {
            $container instanceof Channel => ChannelMember::query()
                ->where('channel_id', $container->id),

            $container instanceof Conversation => ConversationParticipant::query()
                ->where('conversation_id', $container->id)
                ->whereNull('left_at'),

            // The container was deleted out from under the huddle; there is
            // nobody left to tell.
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query
            ->when($except !== null, fn ($rows) => $rows->where('user_id', '!=', $except->id))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
