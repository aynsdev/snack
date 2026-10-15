<?php

namespace App\Actions\Projects;

use App\Events\TaskChanged;
use App\Models\Task;

/**
 * Redraws the parents of a card that just changed.
 *
 * A parent's card carries a "2/3" subtask badge, counted from its children,
 * so filing, finishing, moving, re-parenting, or deleting a subtask changes
 * what the parent's card should say. Re-sending the parent puts the new count
 * on every open board instead of waiting for the next reload.
 */
class AnnounceParentProgress
{
    /**
     * @param  array<int, int|null>  $parentIds  nulls and repeats are ignored
     */
    public function announce(array $parentIds): void
    {
        $ids = array_values(array_unique(array_filter($parentIds)));

        if ($ids === []) {
            return;
        }

        Task::query()
            ->whereKey($ids)
            ->withCardData()
            ->get()
            ->each(fn (Task $parent) => event(TaskChanged::for($parent, 'updated')));
    }
}
