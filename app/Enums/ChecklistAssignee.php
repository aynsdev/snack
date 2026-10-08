<?php

namespace App\Enums;

/**
 * Who a checklist step falls to, before it is resolved to a person: HR (whoever starts the checklist), the employee's manager, or the employee.
 */
enum ChecklistAssignee: string
{
    case Hr = 'hr';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'HR',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
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
