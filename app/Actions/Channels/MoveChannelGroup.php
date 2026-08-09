<?php

namespace App\Actions\Channels;

use App\Models\ChannelGroup;
use Illuminate\Support\Facades\DB;

/**
 * Moves a group one place up or down its owner's channel list.
 *
 * The swap-rather-than-renumber reasoning is {@see App\Actions\Projects\MoveProjectGroup}'s:
 * the move writes exactly two rows, so nothing recomputes an order from a list
 * it may have read before somebody else changed it.
 *
 * The neighbour search is scoped to the same owner as well as the same
 * workspace. Positions are only meaningful within one person's list, and two
 * people's groups can hold the same numbers; without `user_id` a move would
 * happily swap positions with a stranger's heading.
 *
 * A group already at the end of the list in the direction asked for is a no-op
 * rather than an error: the button that sent it is disabled, and a request that
 * raced past that is asking for a state the list is already in.
 */
class MoveChannelGroup
{
    public function handle(ChannelGroup $group, bool $up): bool
    {
        return DB::transaction(function () use ($group, $up): bool {
            $neighbour = ChannelGroup::query()
                ->where('workspace_id', $group->workspace_id)
                ->where('user_id', $group->user_id)
                ->whereKeyNot($group->id)
                ->when(
                    $up,
                    fn ($query) => $query->where('position', '<=', $group->position)->orderByDesc('position')->orderByDesc('id'),
                    fn ($query) => $query->where('position', '>=', $group->position)->orderBy('position')->orderBy('id'),
                )
                // Locked because the swap reads both positions and writes both:
                // two moves crossing the same seam at once would otherwise each
                // write the value the other just replaced.
                ->lockForUpdate()
                ->first();

            if ($neighbour === null) {
                return false;
            }

            [$group->position, $neighbour->position] = [$neighbour->position, $group->position];

            /*
             * Equal positions mean the two rows were ordering by id, and
             * swapping equal numbers changes nothing. Push them apart instead,
             * keeping the pair adjacent to everything around them.
             */
            if ($group->position === $neighbour->position) {
                $group->position += $up ? -1 : 1;
            }

            $group->save();
            $neighbour->save();

            return true;
        });
    }
}
