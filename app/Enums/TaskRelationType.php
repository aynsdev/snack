<?php

namespace App\Enums;

/**
 * How one card relates to another.
 *
 * Each type reads one way from the card it was said from and another way from
 * the card it points at — PWA-1 "blocks" PWA-2, and PWA-2 "is blocked by"
 * PWA-1 — so one row serves both cards. `relates_to` reads the same from both.
 */
enum TaskRelationType: string
{
    case Blocks = 'blocks';

    case RelatesTo = 'relates_to';

    case Duplicates = 'duplicates';

    /**
     * The wording on the card the relation was said from.
     */
    public function outward(): string
    {
        return match ($this) {
            self::Blocks => 'blocks',
            self::RelatesTo => 'relates to',
            self::Duplicates => 'duplicates',
        };
    }

    /**
     * The wording on the card it points at.
     */
    public function inward(): string
    {
        return match ($this) {
            self::Blocks => 'is blocked by',
            self::RelatesTo => 'relates to',
            self::Duplicates => 'is duplicated by',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
