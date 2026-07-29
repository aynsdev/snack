<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;

/**
 * Removes a user from a channel.
 *
 * The membership row is deleted outright rather than soft-deleted: read state
 * is not worth preserving for someone who left, and re-joining should feel like
 * a fresh start. Their messages are untouched.
 */
class LeaveChannel
{
    public function handle(Channel $channel, User $user): void
    {
        $channel->members()->where('user_id', $user->id)->delete();

        $channel->unsetRelation('members');
    }
}
