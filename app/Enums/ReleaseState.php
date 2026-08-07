<?php

namespace App\Enums;

/**
 * Whether a version has gone out.
 *
 * Derived from `releases.released_at` rather than stored, the same way
 * {@see SprintState} is derived from a sprint's timestamps: a stored word is a
 * second source of truth, and the one that is wrong is always the one being
 * read. See {@see App\Models\Release::state()}.
 */
enum ReleaseState: string
{
    /** Still being filled. What the board is working towards. */
    case Unreleased = 'unreleased';

    /** Shipped. A record of what went out, and when. */
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Unreleased => 'Unreleased',
            self::Released => 'Released',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
