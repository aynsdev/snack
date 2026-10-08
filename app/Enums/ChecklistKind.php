<?php

namespace App\Enums;

/**
 * Which way a checklist takes somebody: into the company, or out of it.
 */
enum ChecklistKind: string
{
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Offboarding => 'Offboarding',
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
