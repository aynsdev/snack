import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { resetAssetViewCache, useAssetView } from '@/hooks/use-asset-view';

beforeEach(() => {
    localStorage.clear();
    // The store outlives a single render tree, which is the point of it.
    resetAssetViewCache();
});

describe('useAssetView', () => {
    it('starts as a gallery', () => {
        const { result } = renderHook(() => useAssetView('snack-hq'));

        expect(result.current[0]).toBe('grid');
    });

    it('remembers the choice across mounts', () => {
        const first = renderHook(() => useAssetView('snack-hq'));

        act(() => first.result.current[1]('list'));
        expect(first.result.current[0]).toBe('list');

        first.unmount();

        const second = renderHook(() => useAssetView('snack-hq'));
        expect(second.result.current[0]).toBe('list');
    });

    /*
     * The shell stays mounted when the rail switches workspaces, so a hook that
     * only read storage in its initialiser would show one job's choice at
     * another's library forever.
     */
    it('re-reads when the workspace changes under it', () => {
        const { result, rerender } = renderHook(
            ({ slug }) => useAssetView(slug),
            { initialProps: { slug: 'snack-hq' } },
        );

        act(() => result.current[1]('list'));

        rerender({ slug: 'other-co' });
        expect(result.current[0]).toBe('grid');

        rerender({ slug: 'snack-hq' });
        expect(result.current[0]).toBe('list');
    });

    it('ignores junk in the slot', () => {
        localStorage.setItem('snack:asset-view:snack-hq', 'carousel');

        const { result } = renderHook(() => useAssetView('snack-hq'));

        expect(result.current[0]).toBe('grid');
    });
});
