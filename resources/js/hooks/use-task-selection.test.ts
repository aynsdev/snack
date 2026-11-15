import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    allSelected,
    selectedIn,
    useTaskSelection,
} from '@/hooks/use-task-selection';

const ORDER = [10, 11, 12, 13, 14];

describe('useTaskSelection', () => {
    it('ticks and unticks one card at a time', () => {
        const { result } = renderHook(() => useTaskSelection());

        act(() => result.current.toggle(11));
        act(() => result.current.toggle(13));
        expect([...result.current.selected]).toEqual([11, 13]);

        act(() => result.current.toggle(11));
        expect([...result.current.selected]).toEqual([13]);
    });

    it('ticks the run between the last card and this one on shift-click, in the order shown', () => {
        const { result } = renderHook(() => useTaskSelection());

        act(() => result.current.toggle(13, { order: ORDER }));
        act(() => result.current.toggle(10, { range: true, order: ORDER }));

        expect(selectedIn(result.current.selected, ORDER)).toEqual([
            10, 11, 12, 13,
        ]);
    });

    it('treats a shift-click with nothing ticked yet as an ordinary tick', () => {
        const { result } = renderHook(() => useTaskSelection());

        act(() => result.current.toggle(12, { range: true, order: ORDER }));

        expect([...result.current.selected]).toEqual([12]);
    });

    it('ticks a whole list, then unticks it when every card is already ticked', () => {
        const { result } = renderHook(() => useTaskSelection());

        act(() => result.current.toggle(11));
        act(() => result.current.toggleAll([10, 11, 12]));
        expect(selectedIn(result.current.selected, ORDER)).toEqual([
            10, 11, 12,
        ]);

        act(() => result.current.toggleAll([10, 11, 12]));
        expect([...result.current.selected]).toEqual([]);
    });

    it('clears everything', () => {
        const { result } = renderHook(() => useTaskSelection());

        act(() => result.current.toggleAll(ORDER));
        act(() => result.current.clear());

        expect(result.current.selected.size).toBe(0);
    });
});

describe('selectedIn', () => {
    it('drops ids of cards no longer listed, so the count matches what is on screen', () => {
        expect(selectedIn(new Set([10, 99, 12]), ORDER)).toEqual([10, 12]);
    });
});

describe('allSelected', () => {
    it('ticks a list header only when every card in it is ticked', () => {
        expect(allSelected(new Set(), [1, 2])).toBe(false);
        expect(allSelected(new Set([1]), [1, 2])).toBe(false);
        expect(allSelected(new Set([1, 2]), [1, 2])).toBe(true);
        expect(allSelected(new Set([1]), [])).toBe(false);
    });
});
