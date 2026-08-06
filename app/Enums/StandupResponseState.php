<?php

namespace App\Enums;

/**
 * What one person did with the question they were asked.
 *
 * `Skipped` is distinct from `Pending` on purpose: "I have nothing to report"
 * and "we never heard from them" read identically in a summary that only counts
 * answers, and they mean opposite things to whoever runs the team. Saying so
 * explicitly also stops the reminder pass from chasing somebody who has already
 * dealt with it.
 */
enum StandupResponseState: string
{
    /** Asked, not yet answered. */
    case Pending = 'pending';

    /** Answered. */
    case Submitted = 'submitted';

    /** Answered with "nothing from me today". */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Submitted => 'Answered',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * Whether the person still owes the standup something — which is the same
     * question as whether they should be reminded.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
