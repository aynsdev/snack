<?php

namespace App\Enums;

/**
 * Where an employee is in their employment lifecycle.
 *
 * Kept apart from the workspace membership status: somebody can be suspended
 * from the chat while still employed, and a terminated employee's record
 * outlives the membership that created it.
 */
enum EmploymentStatus: string
{
    case Probation = 'probation';
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Probation => 'Probation',
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Terminated => 'Terminated',
        };
    }

    public function isEmployed(): bool
    {
        return $this !== self::Terminated;
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
