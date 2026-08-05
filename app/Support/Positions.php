<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Sparse ordering for anything that is arranged by dragging.
 *
 * # Why not an index
 *
 * The obvious model — position 0, 1, 2 — makes every drop a rewrite of the whole
 * list. On a board that is dozens of rows for one card moving one place, and two
 * people dragging on the same board a second apart produce two renumberings that
 * each believe they started from the truth. The second one wins and the first
 * one's move disappears.
 *
 * So positions are sparse and only the moved row is written: a card dropped
 * between two others takes the midpoint of its neighbours. Nothing else in the
 * list changes, so concurrent drags in different parts of a column do not touch
 * each other at all.
 *
 * # When the gap runs out
 *
 * Repeatedly dropping into the same seam halves the gap each time, and after
 * about thirty such moves there is no integer left between the neighbours.
 * {@see needsRebalance()} says so, and the caller renumbers that one list — see
 * {@see App\Actions\Projects\MoveTask}. It is rare, it is bounded, and it is
 * better than paying for it on every move.
 */
final class Positions
{
    /** The distance between freshly appended rows. */
    public const GAP = 65_536;

    /**
     * Where a row dropped between these two neighbours goes.
     *
     * Either side may be null, which is what "dropped at the top" and "dropped
     * at the bottom" mean.
     */
    public static function between(?int $before, ?int $after): int
    {
        if ($before === null && $after === null) {
            return self::GAP;
        }

        if ($before === null) {
            return $after - self::GAP;
        }

        if ($after === null) {
            return $before + self::GAP;
        }

        return intdiv($before + $after, 2);
    }

    /**
     * Whether the neighbours are now too close together to insert between.
     *
     * Checked *before* the insert: with adjacent integers the midpoint equals
     * the row above, and two rows sharing a position order arbitrarily.
     */
    public static function needsRebalance(?int $before, ?int $after): bool
    {
        return $before !== null && $after !== null && $after - $before <= 1;
    }

    /**
     * Evenly spaced positions for a list that has been squeezed flat, in the
     * order given.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $items
     * @return array<int, int> one position per item, in the same order
     */
    public static function spread(Collection|array $items): array
    {
        $count = $items instanceof Collection ? $items->count() : count($items);

        if ($count === 0) {
            return [];
        }

        return array_map(
            static fn (int $index): int => ($index + 1) * self::GAP,
            range(0, $count - 1),
        );
    }
}
