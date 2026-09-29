<?php

namespace App\Enums;

/**
 * What a line of employment history records.
 */
enum EmploymentChange: string
{
    case Hired = 'hired';
    case Promoted = 'promoted';
    case Transferred = 'transferred';
    case RoleChanged = 'role_changed';
    case Rehired = 'rehired';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Hired => 'Hired',
            self::Promoted => 'Promoted',
            self::Transferred => 'Transferred',
            self::RoleChanged => 'Role changed',
            self::Rehired => 'Rehired',
            self::Terminated => 'Terminated',
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
