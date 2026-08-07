import { describe, expect, it } from 'vitest';
import { continuesGroup, dayLabel, isSameDay } from '@/lib/message-grouping';

function at(iso: string, senderId: number | null = 1) {
    return {
        created_at: iso,
        sender: senderId === null ? null : { id: senderId },
    };
}

/**
 * Message grouping.
 *
 * This rule decides what the timeline actually looks like, and it is far easier
 * to pin down here than through rendered output.
 */
describe('continuesGroup', () => {
    it('groups consecutive messages from the same person', () => {
        expect(
            continuesGroup(
                at('2026-07-29T10:00:00Z'),
                at('2026-07-29T10:01:00Z'),
            ),
        ).toBe(true);
    });

    it('does not group across authors', () => {
        expect(
            continuesGroup(
                at('2026-07-29T10:00:00Z', 1),
                at('2026-07-29T10:00:30Z', 2),
            ),
        ).toBe(false);
    });

    it('does not group two webhook names behind one bot account', () => {
        // One hook can post as "CI" then "Alerts" from the same URL, and both
        // are the same sender row. Grouping on the sender alone would fold them
        // into one block under whichever name went first.
        expect(
            continuesGroup(
                { ...at('2026-07-29T10:00:00Z'), webhook: { username: 'CI' } },
                {
                    ...at('2026-07-29T10:00:30Z'),
                    webhook: { username: 'Alerts' },
                },
            ),
        ).toBe(false);
    });

    it('groups a webhook posting repeatedly under one name', () => {
        expect(
            continuesGroup(
                { ...at('2026-07-29T10:00:00Z'), webhook: { username: 'CI' } },
                { ...at('2026-07-29T10:00:30Z'), webhook: { username: 'CI' } },
            ),
        ).toBe(true);
    });

    it('breaks the group after the time window', () => {
        // An hour later is a new thought, not a continuation.
        expect(
            continuesGroup(
                at('2026-07-29T10:00:00Z'),
                at('2026-07-29T11:00:00Z'),
            ),
        ).toBe(false);
    });

    it('groups right up to the window edge', () => {
        expect(
            continuesGroup(
                at('2026-07-29T10:00:00Z'),
                at('2026-07-29T10:04:59Z'),
            ),
        ).toBe(true);
    });

    it('never groups the first message', () => {
        expect(continuesGroup(null, at('2026-07-29T10:00:00Z'))).toBe(false);
    });

    it('never groups a message with no author', () => {
        // System messages stand alone; attributing them to whoever spoke last
        // would read as that person having said it.
        expect(
            continuesGroup(
                at('2026-07-29T10:00:00Z', 1),
                at('2026-07-29T10:00:30Z', null),
            ),
        ).toBe(false);
    });

    it('does not group across a day boundary', () => {
        const late = new Date('2026-07-29T23:59:00');
        const early = new Date('2026-07-30T00:01:00');

        // A group straddling a date divider would orphan its header above it.
        expect(
            continuesGroup(
                { created_at: late.toISOString(), sender: { id: 1 } },
                { created_at: early.toISOString(), sender: { id: 1 } },
            ),
        ).toBe(false);
    });
});

describe('dayLabel', () => {
    it('labels today', () => {
        expect(dayLabel(new Date().toISOString())).toBe('Today');
    });

    it('labels yesterday', () => {
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);

        expect(dayLabel(yesterday.toISOString())).toBe('Yesterday');
    });

    it('names the day for anything older', () => {
        const older = new Date();
        older.setDate(older.getDate() - 10);

        expect(dayLabel(older.toISOString())).not.toBe('Today');
        expect(dayLabel(older.toISOString())).not.toBe('Yesterday');
    });
});

describe('isSameDay', () => {
    it('compares calendar days rather than elapsed time', () => {
        const late = new Date('2026-07-29T23:00:00').toISOString();
        const early = new Date('2026-07-30T01:00:00').toISOString();

        expect(isSameDay(late, early)).toBe(false);
    });
});
