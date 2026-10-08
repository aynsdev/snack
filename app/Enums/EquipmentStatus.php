<?php

namespace App\Enums;

/**
 * Where a piece of company equipment is. "Assigned" is only ever set by
 * handing it to somebody, and cleared by taking it back.
 */
enum EquipmentStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case InRepair = 'in_repair';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Assigned => 'Assigned',
            self::InRepair => 'In repair',
            self::Retired => 'Retired',
        };
    }

    /**
     * The statuses HR may set by hand, and an item may come back as.
     *
     * @return array<int, self>
     */
    public static function settable(): array
    {
        return [self::Available, self::InRepair, self::Retired];
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
