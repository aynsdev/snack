<?php

namespace App\Actions\Channels;

use App\Enums\ChannelRole;
use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;

/**
 * Adds a user to a channel.
 *
 * Idempotent: re-joining returns the existing membership rather than failing,
 * which matters because "join" is triggered both explicitly and implicitly (by
 * posting into a channel you are previewing).
 *
 * Authorization is the caller's responsibility — this action is also used to
 * add *other* people to a channel, which is a different policy check than
 * joining one yourself.
 */
class JoinChannel
{
    public function handle(
        Channel $channel,
        User $user,
        ChannelRole $role = ChannelRole::Member,
        ?NotificationLevel $notificationLevel = null,
    ): ChannelMember {
        $membership = ChannelMember::firstOrCreate(
            [
                'channel_id' => $channel->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'notification_level' => $notificationLevel ?? $this->defaultNotificationLevel($channel, $user),
                'joined_at' => now(),
            ],
        );

        $channel->unsetRelation('members');

        return $membership;
    }

    /**
     * The level a new membership starts at.
     *
     * Three answers, narrowest first:
     *
     * 1. An announcement channel is low-traffic and important, so it notifies on
     *    everything. This outranks the member's own default deliberately — the
     *    channel type exists to say "this is the one you should not miss", and a
     *    workspace-wide "mentions only" would quietly defeat it.
     * 2. Otherwise the member's workspace default, set on their notification
     *    preferences. Somebody who has said they want everything, or nothing,
     *    has already answered this question and should not have to answer it
     *    again per channel.
     * 3. Failing that, mentions only. Notifying on every message would be
     *    overwhelming once a workspace grows past a few people.
     *
     * Existing memberships are untouched when the default changes — see
     * {@see App\Actions\Workspaces\UpdateNotificationPreferences}.
     */
    private function defaultNotificationLevel(Channel $channel, User $user): NotificationLevel
    {
        if ($channel->type->requiresElevatedPostingRights()) {
            return NotificationLevel::All;
        }

        return $user->membershipIn($channel->workspace)?->defaultNotificationLevel()
            ?? NotificationLevel::Mentions;
    }
}
