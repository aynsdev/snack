<?php

namespace App\Enums;

/**
 * Where one occurrence of a standup has got to.
 *
 * Two states, because there are only two things anyone asks of a run: is it
 * still taking answers, or has it been written up? "Asked", "reminded" and
 * "closed" are timestamps on the row rather than states here — they are steps
 * that each happened at a moment, and a status column would flatten three facts
 * into one and lose the order they happened in.
 */
enum StandupRunState: string
{
    /** Taking answers. The prompt has gone out and the summary has not. */
    case Collecting = 'collecting';

    /** Written up and posted. Nothing further will be asked or accepted. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => 'Collecting',
            self::Completed => 'Completed',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Collecting;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
