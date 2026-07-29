<?php

namespace App\Enums;

/**
 * Lifecycle state of a workspace membership.
 *
 * Suspending preserves message history and channel memberships while revoking
 * all access, which is what an admin usually wants instead of a hard removal.
 */
enum MemberStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
