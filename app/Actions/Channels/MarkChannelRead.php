<?php

namespace App\Actions\Channels;

use App\Events\ChannelRead;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\User;

/**
 * Advances a user's read pointer in a channel.
 *
 * One row per membership, not one per message read: "unread" is derived by
 * counting messages with an id above the pointer, which stays a single index
 * range scan however long the history grows.
 */
class MarkChannelRead
{
    /**
     * @param  int|null  $messageId  The newest message the client has displayed.
     *                               Null means "everything currently in the channel".
     */
    public function handle(Channel $channel, User $user, ?int $messageId = null): ?ChannelMember
    {
        $membership = $channel->membershipFor($user);

        // Previewing a public channel you have not joined leaves no read state
        // to advance, and should not silently create a membership.
        if ($membership === null) {
            return null;
        }

        $target = $messageId ?? $this->newestMessageId($channel);

        if ($target === null) {
            return $membership;
        }

        /*
         * The pointer only ever moves forward. A tab left open on older history
         * will happily report a stale position when it finally syncs, and
         * honouring that would resurrect unread badges the user already
         * cleared elsewhere.
         */
        if ($membership->last_read_message_id !== null && $target <= $membership->last_read_message_id) {
            return $membership;
        }

        $membership->forceFill([
            'last_read_message_id' => $target,
            'last_read_at' => now(),
        ])->save();

        ChannelRead::dispatch($user->id, $channel->id, $target);

        return $membership;
    }

    private function newestMessageId(Channel $channel): ?int
    {
        $newest = Message::query()
            ->where('channel_id', $channel->id)
            ->rootLevel()
            ->max('id');

        return $newest === null ? null : (int) $newest;
    }
}
