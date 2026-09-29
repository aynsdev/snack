<?php

namespace App\Enums;

/**
 * Why somebody was absent without approved leave.
 */
enum AbsenceType: string
{
    case Unexcused = 'unexcused';
    case Sick = 'sick';
    case Emergency = 'emergency';
    case Late = 'late';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Unexcused => 'Unexcused',
            self::Sick => 'Sick',
            self::Emergency => 'Emergency',
            self::Late => 'Late',
            self::Other => 'Other',
        };
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
