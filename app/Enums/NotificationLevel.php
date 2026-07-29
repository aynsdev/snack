<?php

namespace App\Enums;

/**
 * Per-channel and per-conversation notification preference.
 *
 * Stored on the membership row so a user can be noisy in one channel and quiet
 * in another without duplicating their workspace-wide default.
 */
enum NotificationLevel: string
{
    case All = 'all';
    case Mentions = 'mentions';
    case Nothing = 'nothing';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All messages',
            self::Mentions => 'Mentions only',
            self::Nothing => 'Nothing',
        };
    }

    /**
     * Whether an ordinary message (with no mention of the user) should notify.
     */
    public function notifiesOnEveryMessage(): bool
    {
        return $this === self::All;
    }

    /**
     * Whether a message that mentions the user should notify.
     */
    public function notifiesOnMention(): bool
    {
        return $this !== self::Nothing;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
