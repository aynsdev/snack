import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { useCollapsedChannelSections } from '@/hooks/use-collapsed-channel-sections';

beforeEach(() => {
    localStorage.clear();
});

describe('useCollapsedChannelSections', () => {
    it('starts with everything unfolded', () => {
        const { result } = renderHook(() =>
            useCollapsedChannelSections('snack-hq'),
        );

        expect(result.current.isCollapsed(7)).toBe(false);
        expect(result.current.isCollapsed(null)).toBe(false);
    });

    it('folds and unfolds a section, leftovers included', () => {
        const { result } = renderHook(() =>
            useCollapsedChannelSections('snack-hq'),
        );

        act(() => result.current.toggle(7));
        expect(result.current.isCollapsed(7)).toBe(true);

        // The leftovers key on 'none' and fold like any other section.
        act(() => result.current.toggle(null));
        expect(result.current.isCollapsed(null)).toBe(true);
        expect(result.current.isCollapsed(7)).toBe(true);

        act(() => result.current.toggle(7));
        expect(result.current.isCollapsed(7)).toBe(false);
        expect(result.current.isCollapsed(null)).toBe(true);
    });

    it('survives a reload', () => {
        const first = renderHook(() => useCollapsedChannelSections('snack-hq'));

        act(() => first.result.current.toggle(7));
        first.unmount();

        // A fold is a standing preference, not a per-visit position — so it is
        // in localStorage and a fresh mount reads it back.
        const second = renderHook(() =>
            useCollapsedChannelSections('snack-hq'),
        );

        expect(second.result.current.isCollapsed(7)).toBe(true);
    });

    it('keeps each workspace folds to itself', () => {
        const { result, rerender } = renderHook(
            ({ slug }) => useCollapsedChannelSections(slug),
            { initialProps: { slug: 'snack-hq' } },
        );

        act(() => result.current.toggle(7));

        rerender({ slug: 'other-co' });

        // The shell stays mounted when the rail switches workspaces, so this is
        // the case a hook seeded once at mount gets wrong.
        expect(result.current.isCollapsed(7)).toBe(false);

        rerender({ slug: 'snack-hq' });

        expect(result.current.isCollapsed(7)).toBe(true);
    });

    it('unfolds a section without folding one that is already open', () => {
        const { result } = renderHook(() =>
            useCollapsedChannelSections('snack-hq'),
        );

        act(() => result.current.toggle(7));
        act(() => result.current.expand(7));

        expect(result.current.isCollapsed(7)).toBe(false);

        // expand() is what a drop calls, and a drop into an open section must
        // not close it — which a toggle would.
        act(() => result.current.expand(7));

        expect(result.current.isCollapsed(7)).toBe(false);
    });

    it('reads an unfolded sidebar out of a corrupt stored value', () => {
        localStorage.setItem(
            'snack:collapsed-channel-sections:snack-hq',
            '{not json',
        );

        const { result } = renderHook(() =>
            useCollapsedChannelSections('snack-hq'),
        );

        expect(result.current.isCollapsed(7)).toBe(false);
    });
});
