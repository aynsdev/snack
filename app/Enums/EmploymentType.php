<?php

namespace App\Enums;

/**
 * The basis on which somebody is employed.
 */
enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contractor = 'contractor';
    case Intern = 'intern';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-time',
            self::PartTime => 'Part-time',
            self::Contractor => 'Contractor',
            self::Intern => 'Intern',
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
