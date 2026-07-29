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
                'notification_level' => $notificationLevel ?? $this->defaultNotificationLevel($channel),
                'joined_at' => now(),
            ],
        );

        $channel->unsetRelation('members');

        return $membership;
    }

    /**
     * Announcement channels are low-traffic and important, so they default to
     * notifying on everything; ordinary channels would be overwhelming at that
     * level once a workspace grows.
     */
    private function defaultNotificationLevel(Channel $channel): NotificationLevel
    {
        return $channel->type->requiresElevatedPostingRights()
            ? NotificationLevel::All
            : NotificationLevel::Mentions;
    }
}
