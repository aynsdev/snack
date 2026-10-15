<?php

namespace App\Enums;

enum ConversationType: string
{
    /** Exactly two participants; unique per workspace. */
    case Direct = 'direct';

    /** Three or more participants, optionally named. */
    case Group = 'group';

    /**
     * One person and the workspace's Bite account, titled.
     *
     * Not unique per pair: a person keeps as many Bite chats as they like,
     * the way an assistant app keeps a history. Listed on the Bite page and
     * kept out of the DM list, its badges, and its `/dms` redirect.
     */
    case Bite = 'bite';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct message',
            self::Group => 'Group message',
            self::Bite => 'Bite chat',
        };
    }

    public function isDirect(): bool
    {
        return $this === self::Direct;
    }

    public function isBite(): bool
    {
        return $this === self::Bite;
    }

    /**
     * Only group conversations may be renamed or have participants added later.
     */
    public function supportsParticipantManagement(): bool
    {
        return $this === self::Group;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
