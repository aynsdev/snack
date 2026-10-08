<?php

namespace App\Enums;

/**
 * The escalating steps of discipline, mildest first.
 */
enum DisciplinaryActionType: string
{
    case VerbalWarning = 'verbal_warning';
    case WrittenWarning = 'written_warning';
    case FinalWarning = 'final_warning';
    case Suspension = 'suspension';
    case Dismissal = 'dismissal';

    public function label(): string
    {
        return match ($this) {
            self::VerbalWarning => 'Verbal warning',
            self::WrittenWarning => 'Written warning',
            self::FinalWarning => 'Final warning',
            self::Suspension => 'Suspension',
            self::Dismissal => 'Dismissal',
        };
    }

    /**
     * Whether the action runs for a number of days.
     */
    public function hasDuration(): bool
    {
        return $this === self::Suspension;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
