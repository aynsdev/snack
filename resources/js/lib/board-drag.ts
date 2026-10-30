export const CLICK_LIKE_DRAG_MS = 300;

/**
 * Browsers start a native drag after a few pixels of pointer movement, so a
 * slightly shaky click becomes a drag that lands back in its own column. A drop
 * that quick, into the column it came from, was meant as a click.
 */
export function isClickLikeDrop(
    startedAt: number | null,
    droppedAt: number,
    sameColumn: boolean,
): boolean {
    return (
        sameColumn &&
        startedAt !== null &&
        droppedAt - startedAt < CLICK_LIKE_DRAG_MS
    );
}
