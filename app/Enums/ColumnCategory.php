<?php

namespace App\Enums;

/**
 * What a board column *means*, as distinct from what it is called.
 *
 * A project names its own columns — the board in the brief has "To test", "To
 * fix", "Blocked" and "To release" between In progress and Done — so nothing may
 * infer status from a column's name. Two questions still need answering about
 * every column, and this is what answers them:
 *
 * - Completing a sprint has to know which of its tasks are finished, so the rest
 *   can roll forward instead of being silently closed.
 * - A newly created task has to land somewhere, and that somewhere is the first
 *   unstarted column.
 *
 * Every project therefore has at least one Done column and at least one Unstarted
 * one; see {@see App\Actions\Projects\CreateProject} for the starting set.
 */
enum ColumnCategory: string
{
    /** Not begun. Where a new task lands. */
    case Unstarted = 'unstarted';

    /** Somebody is working on it — including the columns that mean "waiting". */
    case Started = 'started';

    /** Finished. Counts as complete when a sprint closes. */
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Unstarted => 'To do',
            self::Started => 'In progress',
            self::Done => 'Done',
        };
    }

    /**
     * Whether a task sitting in a column of this category is finished.
     */
    public function isComplete(): bool
    {
        return $this === self::Done;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
