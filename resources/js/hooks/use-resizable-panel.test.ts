import { act, renderHook } from '@testing-library/react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import {
    PANEL_MIN_WIDTH,
    useResizablePanel,
} from '@/hooks/use-resizable-panel';

function key(name: string): ReactKeyboardEvent<HTMLElement> {
    return {
        key: name,
        preventDefault: () => {},
    } as unknown as ReactKeyboardEvent<HTMLElement>;
}

beforeEach(() => {
    localStorage.clear();
});

describe('useResizablePanel', () => {
    it('starts at the responsive default, collapsed', () => {
        const { result } = renderHook(() => useResizablePanel('thread:1'));

        expect(result.current.width).toBeNull();
        expect(result.current.expanded).toBe(false);
    });

    it('widens with the arrow keys and never goes below the minimum', () => {
        const { result } = renderHook(() => useResizablePanel('thread:1'));

        act(() => result.current.handleProps.onKeyDown(key('Home')));
        expect(result.current.width).toBe(PANEL_MIN_WIDTH);

        act(() => result.current.handleProps.onKeyDown(key('ArrowLeft')));
        expect(result.current.width).toBe(PANEL_MIN_WIDTH + 32);

        act(() => result.current.handleProps.onKeyDown(key('ArrowRight')));
        act(() => result.current.handleProps.onKeyDown(key('ArrowRight')));
        expect(result.current.width).toBe(PANEL_MIN_WIDTH);
    });

    it('remembers the width and expansion per key', () => {
        const first = renderHook(() => useResizablePanel('thread:1'));

        act(() => first.result.current.handleProps.onKeyDown(key('Home')));
        act(() => first.result.current.toggleExpanded());
        first.unmount();

        const again = renderHook(() => useResizablePanel('thread:1'));
        const someoneElse = renderHook(() => useResizablePanel('thread:2'));

        expect(again.result.current.width).toBe(PANEL_MIN_WIDTH);
        expect(again.result.current.expanded).toBe(true);
        expect(someoneElse.result.current.expanded).toBe(false);
    });

    it('ignores a stored value it cannot read', () => {
        localStorage.setItem('snack:panel:thread:1', '{not json');

        const { result } = renderHook(() => useResizablePanel('thread:1'));

        expect(result.current.width).toBeNull();
        expect(result.current.expanded).toBe(false);
    });
});
