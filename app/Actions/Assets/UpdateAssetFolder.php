<?php

namespace App\Actions\Assets;

use App\Models\AssetFolder;
use Illuminate\Validation\ValidationException;

/**
 * Renames a shelf, rewrites its description, or moves it somewhere else in the
 * tree.
 *
 * # The move is the part with teeth
 *
 * Everything else here is a column write. A move re-parents a whole subtree, and
 * there are two ways to do that which leave the library in a state no query can
 * recover from:
 *
 *  - moving a folder into itself, which produces a one-node cycle, and
 *  - moving it into one of its own descendants, which detaches the entire
 *    subtree from the root and leaves it circling — every folder in it still has
 *    a parent, so nothing looks broken, but none of them are reachable from the
 *    top of the library ever again.
 *
 * Both are refused by walking the *destination's* ancestry and checking whether
 * this folder appears in it. The walk is bounded by
 * {@see AssetFolder::MAX_DEPTH}, and it is the same walk the breadcrumb makes —
 * which matters, because a cycle that got in would hang the breadcrumb too.
 */
class UpdateAssetFolder
{
    /**
     * @param  array{name?: string, description?: string|null}  $attributes
     */
    public function handle(AssetFolder $folder, array $attributes): AssetFolder
    {
        $folder->fill(array_intersect_key($attributes, array_flip(['name', 'description'])));
        $folder->save();

        return $folder->refresh();
    }

    /**
     * Re-parent a folder, `null` meaning the top of the library.
     *
     * Separate from {@see handle()} because it is a different operation with a
     * different failure mode, and because the request that renames a folder must
     * not be able to move it by accident — a `parent_id` that was not sent is
     * not the same as one sent as null.
     */
    public function move(AssetFolder $folder, ?AssetFolder $destination): AssetFolder
    {
        $this->guardCycle($folder, $destination);
        $this->guardDepth($folder, $destination);

        $folder->parent_id = $destination?->id;
        $folder->save();

        return $folder->refresh();
    }

    private function guardCycle(AssetFolder $folder, ?AssetFolder $destination): void
    {
        if ($destination === null) {
            return;
        }

        $wouldLoop = $destination->is($folder)
            || $destination->ancestors()->contains(fn (AssetFolder $ancestor): bool => $ancestor->is($folder));

        if ($wouldLoop) {
            throw ValidationException::withMessages([
                'parent_id' => 'A folder cannot be moved inside itself.',
            ]);
        }
    }

    /**
     * The subtree has to fit under its new parent, not just its root.
     *
     * Checking only the folder being moved would let a three-deep subtree be
     * dropped at depth four, quietly producing exactly the seven-level tree the
     * bound exists to prevent — and the deepest folders in it could then never
     * be added to.
     */
    private function guardDepth(AssetFolder $folder, ?AssetFolder $destination): void
    {
        $height = $this->height($folder);
        $base = $destination === null ? 0 : $destination->depth();

        if ($base + $height <= AssetFolder::MAX_DEPTH) {
            return;
        }

        throw ValidationException::withMessages([
            'parent_id' => 'That would nest folders more than '.AssetFolder::MAX_DEPTH.' levels deep.',
        ]);
    }

    /**
     * How many levels this folder occupies, counting itself: 1 for a leaf.
     */
    private function height(AssetFolder $folder, int $depth = 1): int
    {
        if ($depth >= AssetFolder::MAX_DEPTH) {
            return $depth;
        }

        $tallest = $depth;

        foreach ($folder->children as $child) {
            $tallest = max($tallest, $this->height($child, $depth + 1));
        }

        return $tallest;
    }
}
