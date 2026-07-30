import { afterEach, describe, expect, it, vi } from 'vitest';
import { createNonce } from '@/lib/nonce';

const UUID_SHAPE =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('createNonce', () => {
    it('uses crypto.randomUUID when it is available', () => {
        const randomUUID = vi.fn(() => '11111111-2222-4333-8444-555555555555');

        vi.stubGlobal('crypto', { randomUUID, getRandomValues: vi.fn() });

        expect(createNonce()).toBe('11111111-2222-4333-8444-555555555555');
        expect(randomUUID).toHaveBeenCalled();
    });

    it('still produces a nonce outside a secure context', () => {
        /*
         * The bug this exists for: `crypto.randomUUID` is undefined on a plain
         * HTTP origin, which is exactly how Snack is served in development. The
         * nonce was minted before the send's try/catch, so the TypeError killed
         * the request while the composer cleared itself — messages disappeared
         * with nothing on screen to say so.
         */
        vi.stubGlobal('crypto', {
            getRandomValues: (array: Uint8Array) => {
                array.fill(7);

                return array;
            },
        });

        expect(createNonce()).toMatch(UUID_SHAPE);
    });

    it('falls back again when there is no crypto at all', () => {
        vi.stubGlobal('crypto', undefined);

        expect(createNonce()).toMatch(/^nonce-/);
    });

    it('does not repeat itself', () => {
        const seen = new Set(Array.from({ length: 500 }, () => createNonce()));

        // Two unconfirmed messages sharing a nonce would have the server's
        // confirmation of one silently discard the other.
        expect(seen.size).toBe(500);
    });

    it('does not repeat itself without crypto either', () => {
        vi.stubGlobal('crypto', undefined);

        const seen = new Set(Array.from({ length: 500 }, () => createNonce()));

        expect(seen.size).toBe(500);
    });
});
