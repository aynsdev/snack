<?php

namespace App\Enums;

/**
 * Which slice of the activity feed is open.
 *
 * An enum rather than a validated string because the tab decides which queries
 * run, and "which lists does this tab need" is a property of the tab itself —
 * keeping that answer here is what lets the controller stay a list of three
 * conditionals instead of a map the next tab would have to be added to twice.
 */
enum ActivityTab: string
{
    /** Everything, interleaved newest first. */
    case All = 'all';

    case Notifications = 'notifications';

    case Mentions = 'mentions';

    case Threads = 'threads';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Notifications => 'Notifications',
            self::Mentions => 'Mentions',
            self::Threads => 'Threads',
        };
    }

    public function isAll(): bool
    {
        return $this === self::All;
    }

    public function includesNotifications(): bool
    {
        return $this === self::All || $this === self::Notifications;
    }

    public function includesMentions(): bool
    {
        return $this === self::All || $this === self::Mentions;
    }

    public function includesThreads(): bool
    {
        return $this === self::All || $this === self::Threads;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
