<?php

namespace App\Support;

/**
 * Whether a secret shared with the agent dispatcher is strong enough to sign
 * with — the same test the dispatcher applies at startup, so a secret Snack
 * signs with is one the dispatcher will also accept.
 *
 * It must be random bytes written out as hex, base64 or base64url — what
 * `openssl rand -base64 32` prints — and decode to at least 32 bytes, at least
 * 16 of them different, without repeating a shorter pattern. A memorable
 * phrase fails the first rule; "aaaa…" and "abcabc…" fail the others.
 */
final class SharedSecret
{
    public const MIN_BYTES = 32;

    public const MIN_DISTINCT_BYTES = 16;

    /**
     * What is wrong with the secret, or null when nothing is.
     */
    public static function problem(string $secret): ?string
    {
        $bytes = self::decode($secret);

        if ($bytes === null) {
            return 'it is not hex, base64 or base64url';
        }

        if (strlen($bytes) < self::MIN_BYTES) {
            return 'it decodes to fewer than '.self::MIN_BYTES.' bytes';
        }

        if (count(array_unique(str_split($bytes))) < self::MIN_DISTINCT_BYTES) {
            return 'it has fewer than '.self::MIN_DISTINCT_BYTES.' distinct bytes';
        }

        if (self::repeats($bytes)) {
            return 'it repeats a shorter pattern';
        }

        return null;
    }

    private static function decode(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        // Hex first: every hex string is also valid base64, and would decode
        // to different bytes read that way.
        if (strlen($secret) % 2 === 0 && ctype_xdigit($secret)) {
            return (string) hex2bin($secret);
        }

        if (preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/', $secret) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($secret, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Whether the bytes are one shorter run repeated, whole or in part —
     * "abcabcab" included.
     */
    private static function repeats(string $bytes): bool
    {
        $length = strlen($bytes);

        for ($period = 1; $period <= intdiv($length, 2); $period++) {
            if (substr($bytes, $period) === substr($bytes, 0, $length - $period)) {
                return true;
            }
        }

        return false;
    }
}
