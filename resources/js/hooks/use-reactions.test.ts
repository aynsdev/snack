import { describe, expect, it } from 'vitest';
import { optimistic } from '@/hooks/use-reactions';
import type { ReactionGroup } from '@/types';

/**
 * The optimistic reaction arithmetic.
 *
 * Every transition here is visible to the user the instant they click, before
 * any request lands — so a mistake in this function is one people watch happen
 * rather than one they discover later.
 */
function group(
    emoji: string,
    userIds: number[],
    count = userIds.length,
): ReactionGroup {
    return {
        emoji,
        count,
        user_ids: userIds,
        names: userIds.map((id) => `User ${id}`),
    };
}

describe('optimistic', () => {
    it('creates a pill for an emoji nobody has used', () => {
        expect(optimistic([], 'tada', 1, true)).toEqual([
            { emoji: 'tada', count: 1, user_ids: [1], names: ['You'] },
        ]);
    });

    it('joins an existing pill', () => {
        const next = optimistic([group('tada', [2])], 'tada', 1, true);

        expect(next[0].count).toBe(2);
        expect(next[0].user_ids).toEqual([2, 1]);
    });

    it('leaves a pill others are still in', () => {
        const next = optimistic([group('tada', [1, 2])], 'tada', 1, false);

        expect(next).toHaveLength(1);
        expect(next[0].count).toBe(1);
        expect(next[0].user_ids).toEqual([2]);
    });

    it('removes the pill when the last reactor leaves', () => {
        // A zero-count pill left behind is the visible failure here.
        expect(optimistic([group('tada', [1])], 'tada', 1, false)).toEqual([]);
    });

    it('leaves other emoji untouched', () => {
        const next = optimistic(
            [group('tada', [1]), group('rocket', [2])],
            'tada',
            1,
            false,
        );

        expect(next).toHaveLength(1);
        expect(next[0].emoji).toBe('rocket');
    });

    it('treats removing something absent as already done', () => {
        const current = [group('rocket', [2])];

        expect(optimistic(current, 'tada', 1, false)).toBe(current);
    });

    it('does not mutate the summary it was given', () => {
        const current = [group('tada', [2])];

        optimistic(current, 'tada', 1, true);

        // React compares by reference; mutating in place would leave the pill
        // showing a stale count until something else forced a render.
        expect(current[0].count).toBe(1);
        expect(current[0].user_ids).toEqual([2]);
    });

    it('keeps a count that exceeds the capped id list honest', () => {
        // The server caps `user_ids` at a dozen but `count` stays exact, so
        // leaving must decrement the count even when the id list is truncated.
        const next = optimistic([group('tada', [2, 3], 40)], 'tada', 1, true);

        expect(next[0].count).toBe(41);
    });
});
