import { describe, expect, it } from 'vitest';
import { CLICK_LIKE_DRAG_MS, isClickLikeDrop } from '@/lib/board-drag';

describe('isClickLikeDrop', () => {
    it('treats a quick drop back into the same column as a click', () => {
        expect(isClickLikeDrop(1000, 1000 + CLICK_LIKE_DRAG_MS - 1, true)).toBe(
            true,
        );
    });

    it('keeps slower drags and drops into another column as moves', () => {
        expect(isClickLikeDrop(1000, 1000 + CLICK_LIKE_DRAG_MS, true)).toBe(
            false,
        );
        expect(isClickLikeDrop(1000, 1050, false)).toBe(false);
        expect(isClickLikeDrop(null, 1050, true)).toBe(false);
    });
});
