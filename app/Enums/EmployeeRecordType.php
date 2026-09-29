<?php

namespace App\Enums;

/**
 * The kind of entry filed on an employee's record.
 */
enum EmployeeRecordType: string
{
    case Note = 'note';
    case Commendation = 'commendation';
    case Warning = 'warning';
    case PerformanceReview = 'performance_review';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Commendation => 'Commendation',
            self::Warning => 'Warning',
            self::PerformanceReview => 'Performance review',
            self::Document => 'Document',
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
