<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\User;
use App\Services\Outbound\TaskEligibilityEvents;

/**
 * Removes a user from a channel.
 *
 * The membership row is deleted outright rather than soft-deleted: read state
 * is not worth preserving for someone who left, and re-joining should feel like
 * a fresh start. Their messages are untouched.
 *
 * Leaving a private agent channel means no longer seeing where one's agent
 * works, so the agent dispatcher is told about their open tasks — see
 * {@see TaskEligibilityEvents::aroundViewerChange()}.
 */
class LeaveChannel
{
    public function __construct(private readonly TaskEligibilityEvents $eligibility) {}

    public function handle(Channel $channel, User $user): void
    {
        $this->eligibility->aroundViewerChange(
            $channel->workspace_id,
            $channel->id,
            [$user->id],
            fn () => $channel->members()->where('user_id', $user->id)->delete(),
            fn (int $userId): bool => $this->eligibility->membershipDecidesView($channel->id, $userId),
        );

        $channel->unsetRelation('members');
    }
}
