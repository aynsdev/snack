<?php

namespace App\Enums;

enum ChannelType: string
{
    /** Discoverable in the channel browser; any non-guest member may join. */
    case Public = 'public';

    /** Invite-only and hidden from the browser for non-members. */
    case Private = 'private';

    /** Readable by the whole workspace, but only admins may post. */
    case Announcement = 'announcement';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
            self::Announcement => 'Announcement',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Anyone in the workspace can find and join this channel.',
            self::Private => 'Only invited people can find and join this channel.',
            self::Announcement => 'Everyone can read, but only admins can post.',
        };
    }

    /**
     * Whether membership is required before the channel becomes visible.
     */
    public function isPrivate(): bool
    {
        return $this === self::Private;
    }

    /**
     * Whether a non-member of the channel (but member of the workspace) may
     * preview its contents before joining.
     */
    public function isBrowsable(): bool
    {
        return $this !== self::Private;
    }

    /**
     * Announcement channels restrict posting to workspace admins.
     */
    public function requiresElevatedPostingRights(): bool
    {
        return $this === self::Announcement;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
