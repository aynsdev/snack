<?php

namespace App\Enums;

/**
 * Where one person's copy of a requested document is: not yet uploaded,
 * uploaded and waiting on HR, or decided. A rejected document goes back to the
 * person to upload again.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs upload',
            self::Submitted => 'Awaiting review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Whether the person may upload (again).
     */
    public function acceptsUpload(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
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
