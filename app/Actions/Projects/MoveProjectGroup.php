<?php

namespace App\Actions\Projects;

use App\Models\ProjectGroup;
use Illuminate\Support\Facades\DB;

/**
 * Moves a group one place up or down the project list.
 *
 * # Why a swap rather than a renumbering
 *
 * The move writes exactly two rows: this group and the one it changes places
 * with. Renumbering the whole list instead would mean two people rearranging
 * different ends of it at the same time each write a complete order computed
 * from what they saw, and the later write silently undoes the earlier one —
 * the same reasoning that makes card positions sparse, see {@see MoveTask}.
 *
 * A group already at the end of the list in the direction asked for is a no-op
 * rather than an error: the button that sent it is disabled, and a request that
 * raced past that is asking for a state the list is already in.
 */
class MoveProjectGroup
{
    public function handle(ProjectGroup $group, bool $up): bool
    {
        return DB::transaction(function () use ($group, $up): bool {
            $neighbour = ProjectGroup::query()
                ->where('workspace_id', $group->workspace_id)
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
