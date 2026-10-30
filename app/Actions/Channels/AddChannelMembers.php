<?php

namespace App\Actions\Channels;

use App\Actions\Messages\PostSystemMessage;
use App\Enums\ChannelRole;
use App\Events\ChannelMembershipGranted;
use App\Models\Channel;
use App\Models\User;
use App\Policies\ChannelPolicy;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Support\Collection;

/**
 * Adds other people to a channel, public or private.
 *
 * The counterpart of {@see JoinChannel}, which is somebody enrolling themselves.
 * The distinction is authorization, not mechanics: joining is gated on the
 * channel being open to you, adding is gated on you administering the channel —
 * see {@see ChannelPolicy::manageMembers()}, which this action trusts to have
 * run. It is the only way into a private channel, whose whole point is that no
 * one can let themselves in.
 *
 * # Why this is not just a loop over JoinChannel
 *
 * Two things have to be true of the set rather than of each person: the system
 * message names everybody in one sentence rather than posting a line each, and
 * only the people who were *actually* new are announced — re-adding an existing
 * member is a silent no-op, because two admins adding the same colleague at once
 * is ordinary, not a conflict.
 */
class AddChannelMembers
{
    public function __construct(
        private readonly JoinChannel $joinChannel,
        private readonly PostSystemMessage $systemMessage,
        private readonly OutboundEvents $events,
    ) {}

    /**
     * @param  Collection<int, User>  $users  Verified active members of the workspace.
     * @return Collection<int, User> Those actually added, in the order given.
     */
    public function handle(
        Channel $channel,
        User $actor,
        Collection $users,
        ChannelRole $role = ChannelRole::Member,
    ): Collection {
        $existing = $channel->members()->pluck('user_id')->all();

        $added = $users
            ->reject(fn (User $user): bool => in_array($user->id, $existing, true))
            ->values();

        if ($added->isEmpty()) {
            return $added;
        }

        // One outbound transaction for everybody, each join reporting the
        // assignee it lets see the channel — see JoinChannel.
        $this->events->transaction([$channel->workspace_id], function () use ($channel, $added, $role): void {
            foreach ($added as $user) {
                $this->joinChannel->handle($channel, $user, $role);
            }
        });

        $channel->unsetRelation('members')->unsetRelation('users');

        /*
         * Written into the channel rather than shown as a toast to the admin:
         * "who let this person in here?" is a question the channel has to be
         * able to answer tomorrow, not only to whoever happened to be looking.
         */
        $this->systemMessage->handle($channel, sprintf(
            '%s added %s to the channel.',
            $actor->name,
            $added->map(fn (User $user): string => $user->name)->join(', ', ' and '),
        ));

        ChannelMembershipGranted::dispatch(
            $channel,
            $added->map(fn (User $user): int => $user->id)->all(),
        );

        return $added;
    }
}
