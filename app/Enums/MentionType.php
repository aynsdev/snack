<?php

namespace App\Enums;

enum MentionType: string
{
    /** @username — notifies one person. */
    case User = 'user';

    /** #channel — links a channel, notifies nobody. */
    case Channel = 'channel';

    /** @everyone — notifies every member of the channel. */
    case Everyone = 'everyone';

    /** @here — notifies every currently-online member of the channel. */
    case Here = 'here';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Person',
            self::Channel => 'Channel',
            self::Everyone => 'Everyone',
            self::Here => 'Here',
        };
    }

    /**
     * Broadcast mentions fan out to many recipients and are therefore gated
     * behind {@see WorkspaceRole::canMentionEveryone()}.
     */
    public function isBroadcast(): bool
    {
        return $this === self::Everyone || $this === self::Here;
    }

    /**
     * Whether this mention should produce a notification and bump the unread
     * mention badge. Channel links are purely navigational.
     */
    public function isNotifying(): bool
    {
        return $this !== self::Channel;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
