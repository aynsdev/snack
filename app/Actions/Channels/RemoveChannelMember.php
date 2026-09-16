<?php

namespace App\Actions\Channels;

use App\Actions\Messages\PostSystemMessage;
use App\Events\ChannelMembershipRevoked;
use App\Models\Channel;
use App\Models\User;

class RemoveChannelMember
{
    public function __construct(
        private readonly LeaveChannel $leaveChannel,
        private readonly PostSystemMessage $systemMessage,
    ) {}

    public function handle(Channel $channel, User $actor, User $member): void
    {
        $this->leaveChannel->handle($channel, $member);

        $this->systemMessage->handle(
            $channel,
            "{$actor->name} removed {$member->name} from the channel.",
        );

        ChannelMembershipRevoked::dispatch($channel, $member->id);
    }
}
