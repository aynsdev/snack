<?php

namespace App\Actions\Channels;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;

/**
 * Changes one person's notification settings for a channel.
 *
 * The mirror of {@see App\Actions\Conversations\UpdateConversationPreferences},
 * and deliberately the same shape: these live on the membership row, so muting
 * is per-person — silencing a busy channel must not silence it for everyone
 * else in it. Nothing is broadcast, because a preference is private state and
 * the only client that needs to know is the one that asked.
 */
class UpdateChannelPreferences
{
    public function handle(
        Channel $channel,
        User $user,
        ?bool $isMuted = null,
        ?NotificationLevel $level = null,
    ): ?ChannelMember {
        $membership = $channel->members()->where('user_id', $user->id)->first();

        if ($membership === null) {
            return null;
        }

        $membership->forceFill([
            // Null means "leave this one alone", so a request that only mutes
            // does not silently reset the notification level as a side effect.
            'is_muted' => $isMuted ?? $membership->is_muted,
            'notification_level' => $level ?? $membership->notification_level,
        ])->save();

        $channel->unsetRelation('members');

        return $membership;
    }
}
