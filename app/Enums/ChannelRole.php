<?php

namespace App\Enums;

/**
 * A member's role inside one channel, independent of their workspace role.
 *
 * A workspace Member can be the admin of a channel they created, and a
 * workspace Admin is always treated as a channel admin by the policies.
 */
enum ChannelRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Channel admin',
            self::Member => 'Member',
        };
    }

    /**
     * May rename, re-describe, archive, and manage the members of the channel.
     */
    public function canManageChannel(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
