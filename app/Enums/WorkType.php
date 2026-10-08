<?php

namespace App\Enums;

/**
 * Where somebody does their work.
 */
enum WorkType: string
{
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Onsite => 'On-site',
            self::Remote => 'Remote',
            self::Hybrid => 'Hybrid',
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
