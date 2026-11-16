import { useCallback, useMemo, useState } from 'react';

/**
 * Which cards the reader has ticked on a board or backlog, for the bulk action
 * bar.
 *
 * Held as ids rather than tasks, so a card that changes — from the bar itself,
 * or over the socket — stays selected as the card it now is. Ids of cards that
 * have left the list (deleted, or moved off this board) are dropped by
 * {@see selectedIn}, so the count never includes something the reader cannot
 * see.
 *
 * Shift-click selects the run between the last card ticked and this one, in
 * the order the caller passes — the order the reader sees.
 */
export function useTaskSelection() {
    const [selected, setSelected] = useState<ReadonlySet<number>>(
        () => new Set(),
    );
    const [anchor, setAnchor] = useState<number | null>(null);

    const toggle = useCallback(
        (
            taskId: number,
            options: { range?: boolean; order?: readonly number[] } = {},
        ) => {
            const { range = false, order = [] } = options;
            const from = anchor === null ? -1 : order.indexOf(anchor);
            const to = order.indexOf(taskId);

            setSelected((current) => {
                const next = new Set(current);

                if (range && from !== -1 && to !== -1) {
                    const [start, end] = from < to ? [from, to] : [to, from];

                    order.slice(start, end + 1).forEach((id) => next.add(id));

                    return next;
                }

                if (next.has(taskId)) {
                    next.delete(taskId);
                } else {
                    next.add(taskId);
                }

                return next;
            });

            setAnchor(taskId);
        },
        [anchor],
    );

    /** Tick every card in a list, or untick them all when all are ticked. */
    const toggleAll = useCallback((taskIds: readonly number[]) => {
        setSelected((current) => {
            const next = new Set(current);
            const everyOne = taskIds.every((id) => current.has(id));

            taskIds.forEach((id) =>
                everyOne ? next.delete(id) : next.add(id),
            );

            return next;
        });
    }, []);

    const clear = useCallback(() => {
        setSelected(new Set());
        setAnchor(null);
    }, []);

    return useMemo(
        () => ({ selected, toggle, toggleAll, clear }),
        [selected, toggle, toggleAll, clear],
    );
}

/** The selected ids that are still among these cards, in their order. */
export function selectedIn(
    selected: ReadonlySet<number>,
    taskIds: readonly number[],
): number[] {
    return taskIds.filter((id) => selected.has(id));
}

/**
 * Whether every card in a list is ticked — the list header's box.
 *
 * Only all-or-not: the shared Checkbox draws the same tick for its
 * indeterminate state, so a partly ticked list would read as fully ticked.
 */
export function allSelected(
    selected: ReadonlySet<number>,
    taskIds: readonly number[],
): boolean {
    return taskIds.length > 0 && taskIds.every((id) => selected.has(id));
}
