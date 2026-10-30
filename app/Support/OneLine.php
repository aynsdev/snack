<?php

namespace App\Support;

use App\Services\MessageBody;

/**
 * Text that Snack prints as one line: a person's name, a channel's name and
 * topic, a file's name.
 *
 * They are printed into places that are read line by line — a thread an
 * assistant reads over MCP, a notification, an export — so a line break inside
 * one could forge what looks like the next line: another author, an "edited"
 * or "via agent" marker that was never there. Invisible and direction-changing
 * characters go too, so what is shown is what is stored.
 */
final class OneLine
{
    /**
     * C0 and C1 controls (newline, carriage return, tab and U+0085 among
     * them), DEL, and the Unicode line and paragraph separators — each becomes
     * a space.
     */
    private const BREAKING = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u';

    /**
     * Zero-width characters and bidirectional overrides — removed outright,
     * the same class {@see MessageBody} strips from messages.
     */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function clean(string $text): string
    {
        return str($text)
            ->replaceMatches(self::INVISIBLE, '')
            ->replaceMatches(self::BREAKING, ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    public static function cleanOrNull(?string $text): ?string
    {
        return $text === null ? null : self::clean($text);
    }
}
