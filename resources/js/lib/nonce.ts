/**
 * A unique id for a message the client has sent but the server has not
 * confirmed yet.
 *
 * # Why this is not just `crypto.randomUUID()`
 *
 * `crypto.randomUUID` is only defined in a **secure context** — HTTPS, or the
 * literal `localhost`. Snack is deliberately served over plain HTTP at
 * `snack.test` in development so that Reverb's `ws://` works, and that origin is
 * *not* a secure context. There, `crypto.randomUUID` is `undefined`.
 *
 * That mattered more than it sounds. The nonce was minted at the top of the
 * optimistic send, before its try/catch, so the `TypeError` escaped: no request
 * was made, no pending message was recorded, and the composer — which clears
 * itself as soon as it hands the text over — went blank anyway. Every message
 * typed in development was silently discarded, with nothing on screen to say so.
 *
 * `crypto.getRandomValues` carries no such restriction, so the fallback is still
 * genuinely random rather than a guess at uniqueness.
 */
export function createNonce(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.getRandomValues === 'function'
    ) {
        const bytes = crypto.getRandomValues(new Uint8Array(16));

        // Version 4, variant 1 — the same shape as randomUUID would return, so
        // nothing downstream can tell which branch produced it.
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = Array.from(bytes, (byte) =>
            byte.toString(16).padStart(2, '0'),
        ).join('');

        return [
            hex.slice(0, 8),
            hex.slice(8, 12),
            hex.slice(12, 16),
            hex.slice(16, 20),
            hex.slice(20),
        ].join('-');
    }

    /*
     * No crypto at all. The nonce only has to be unique among *this* tab's
     * unconfirmed messages — it never reaches the server and never identifies
     * anything — so a timestamp and a counter are sufficient here.
     */
    counter += 1;

    return `nonce-${Date.now().toString(36)}-${counter.toString(36)}`;
}

let counter = 0;
