<?php

namespace App\Enums;

enum ConversationType: string
{
    /** Exactly two participants; unique per workspace. */
    case Direct = 'direct';

    /** Three or more participants, optionally named. */
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct message',
            self::Group => 'Group message',
        };
    }

    public function isDirect(): bool
    {
        return $this === self::Direct;
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
