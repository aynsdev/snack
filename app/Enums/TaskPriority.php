<?php

namespace App\Enums;

/**
 * How urgent a task is, on the five-step scale every issue tracker converges on.
 *
 * Stored as a string rather than as the integer it sorts by, so the column reads
 * as itself in a query and a later insertion between two steps does not renumber
 * the rows underneath it. {@see rank()} is what ordering uses.
 */
enum TaskPriority: string
{
    case Highest = 'highest';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Lowest = 'lowest';

    public function label(): string
    {
        return match ($this) {
            self::Highest => 'Highest',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Lowest => 'Lowest',
        };
    }

    /**
     * Descending urgency, so "order by rank" is "most urgent first".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Highest => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
            self::Lowest => 4,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
