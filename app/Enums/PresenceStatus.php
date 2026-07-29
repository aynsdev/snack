<?php

namespace App\Enums;

/**
 * A user's availability.
 *
 * Online/Away are derived from live Reverb presence channels and Redis
 * heartbeats. DoNotDisturb is a deliberate user choice and is the only value
 * that suppresses notification delivery.
 */
enum PresenceStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Offline = 'offline';
    case DoNotDisturb = 'do_not_disturb';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Active',
            self::Away => 'Away',
            self::Offline => 'Offline',
            self::DoNotDisturb => 'Do not disturb',
        };
    }

    /**
     * Whether the user holds at least one live WebSocket connection.
     */
    public function isConnected(): bool
    {
        return $this !== self::Offline;
    }

    /**
     * Whether push and email notifications should be withheld.
     */
    public function suppressesNotifications(): bool
    {
        return $this === self::DoNotDisturb;
    }

    /**
     * Whether an @here mention should reach this user.
     */
    public function receivesHereMentions(): bool
    {
        return $this === self::Online;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
