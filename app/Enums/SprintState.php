<?php

namespace App\Enums;

/**
 * Where a sprint is in its life.
 *
 * Derived from the two timestamps on the row — `started_at` and `completed_at` —
 * rather than stored, because a stored state is a second source of truth that
 * can disagree with the dates the burndown is drawn from. See
 * {@see App\Models\Sprint::state()}.
 */
enum SprintState: string
{
    /** Planned but not begun. Its tasks are still being arranged in the backlog. */
    case Planned = 'planned';

    /** Running. Its tasks are what the board shows. */
    case Active = 'active';

    /** Closed. Whatever was unfinished has already been rolled forward. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Completed => 'Completed',
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
