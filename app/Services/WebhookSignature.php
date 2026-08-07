<?php

namespace App\Services;

use App\Models\IncomingWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The optional signature on an incoming webhook delivery.
 *
 * A caller with a signing secret sends two headers:
 *
 *     X-Snack-Timestamp: 1785000000
 *     X-Snack-Signature: v1=<hex hmac-sha256>
 *
 * where the signed string is `v1:{timestamp}:{raw body}`. Slack's own scheme,
 * near enough, because it is the one this shape's audience has already
 * implemented once.
 *
 * # Why the timestamp is inside the signed string
 *
 * Signing the body alone produces a signature that stays valid forever. Anyone
 * who captures one request — from a proxy log, a CI transcript, a mirror — can
 * replay it verbatim, and every replay is a genuine message in a channel. Since
 * webhook payloads are things like "deploy succeeded", a replay is not a
 * harmless duplicate; it is a lie about the present.
 *
 * Putting the timestamp inside the HMAC binds the two: the caller cannot present
 * a fresh timestamp with an old signature, because changing the timestamp
 * changes what must have been signed. That is the whole reason it goes in the
 * string rather than merely travelling beside it in a header.
 *
 * # Why the raw body
 *
 * The signature covers the exact bytes that arrived, not a re-encoding of them.
 * Two JSON serialisers disagree about key order, unicode escaping, and float
 * formatting, so signing a decoded-then-re-encoded payload would fail for
 * reasons no one could see. This is also the one thing callers get wrong: in
 * Laravel, `Http::post($url, $array)` encodes the array *after* you signed your
 * own copy of it. The documentation on the webhooks screen says so explicitly.
 */
class WebhookSignature
{
    public const TIMESTAMP_HEADER = 'X-Snack-Timestamp';

    public const SIGNATURE_HEADER = 'X-Snack-Signature';

    /**
     * The scheme version, carried in the signature so a future change can be
     * rolled out without every existing caller breaking on the same day.
     */
    private const VERSION = 'v1';

    /**
     * Why a delivery's signature was refused.
     *
     * Distinguished because these are three different mistakes with three
     * different fixes, and the caller is a developer reading a curl response
     * once, in a terminal, while something is broken. See the note in
     * {@see App\Http\Controllers\Webhooks\WebhookDeliveryController} on why
     * saying this much is the right trade here.
     */
    public const MISSING = 'missing';

    public const STALE = 'stale';

    public const INVALID = 'invalid';

    /**
     * A fresh secret.
     *
     * 64 hex characters from a CSPRNG — the same entropy as the SHA-256 it
     * keys, so the secret is never the weak half of the construction.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check a request against a hook's secret.
     *
     * @return string|null One of the constants above, or null when the request
     *                     is properly signed. Null is "fine" so the caller reads
     *                     as `if ($reason !== null)`.
     */
    public function verify(IncomingWebhook $webhook, Request $request): ?string
    {
        $secret = $webhook->signing_secret;

        if ($secret === null) {
            // Unsigned hook: the URL is the credential, as it always was.
            return null;
        }

        $timestamp = $request->header(self::TIMESTAMP_HEADER);
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($timestamp) || ! is_string($signature) || $timestamp === '' || $signature === '') {
            return self::MISSING;
        }

        if (! ctype_digit($timestamp)) {
            return self::MISSING;
        }

        /*
         * The replay window, checked before the HMAC rather than after.
         *
         * Not as an optimisation — the HMAC is microseconds — but because a
         * stale request is a *different answer* from a wrong one, and computing
         * the digest first would tempt a later edit into collapsing the two.
         */
        if (abs(now()->getTimestamp() - (int) $timestamp) > $this->toleranceSeconds()) {
            return self::STALE;
        }

        $expected = $this->sign($secret, $timestamp, $request->getContent());

        /*
         * `hash_equals`, not `===`. String comparison returns as soon as it
         * finds a differing byte, so how long it took is how many leading bytes
         * were right — enough, over many attempts, to recover a signature a byte
         * at a time. This compares in time proportional to length alone.
         */
        return hash_equals($expected, $signature) ? null : self::INVALID;
    }

    /**
     * The signature a caller should send.
     *
     * Public because the documentation panel and the tests both need to produce
     * one, and a second implementation of this string is a second chance to
     * disagree with the verifier about what is being signed.
     */
    public function sign(string $secret, string $timestamp, string $body): string
    {
        $payload = self::VERSION.':'.$timestamp.':'.$body;

        return self::VERSION.'='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * How far out of step a caller's clock may be.
     *
     * Generous enough to survive a build server whose NTP has drifted, short
     * enough that a captured request stops being useful the same coffee break.
     */
    private function toleranceSeconds(): int
    {
        return (int) config('snack.webhooks.signature_tolerance_seconds', 300);
    }

    /**
     * What to tell the caller, in the terms they can act on.
     */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::MISSING => 'This webhook requires a signature. Send '
                .self::TIMESTAMP_HEADER.' and '.self::SIGNATURE_HEADER.' headers.',
            self::STALE => 'That signature\'s timestamp is too far from the server\'s clock.',
            default => 'That signature did not match. Check you signed the exact bytes you sent.',
        };
    }

    /**
     * A secret, masked for anywhere it should be recognisable but not readable.
     */
    public static function mask(string $secret): string
    {
        return Str::limit($secret, 6, '').str_repeat('•', 12);
    }
}
