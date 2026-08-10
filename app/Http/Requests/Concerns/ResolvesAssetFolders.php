<?php

namespace App\Http\Requests\Concerns;

use App\Models\AssetFolder;
use App\Models\Workspace;
use Closure;

/**
 * Turns a `folder_id` / `parent_id` a request names into a real folder in *this*
 * workspace.
 *
 * # Why the id is not trusted, and not merely `exists`
 *
 * Both fields name a row the route did not authorize. A bare `exists` rule would
 * happily confirm a folder in somebody else's workspace, and filing an asset
 * into it would put a company's contract on another tenant's shelf — where it
 * would then be perfectly visible to every member of that tenant, because the
 * library has no private folders. So the lookup is scoped to the workspace in
 * the query rather than checked afterwards, and a miss is a validation failure
 * rather than a silent fall back to the root.
 *
 * # Null is a destination, absent is not
 *
 * `null` means the top of the library and is a legitimate thing to send — it is
 * how something is moved back out of a folder. A field that was never sent means
 * "leave it where it is". The two are distinguished by {@see hasFolderKey()}
 * rather than by the resolved value, because both resolve to `null`, and
 * conflating them would make every rename quietly move the asset to the root.
 */
trait ResolvesAssetFolders
{
    /**
     * The folder the given key names, or null for the top of the library.
     */
    protected function resolveFolder(Workspace $workspace, string $key): ?AssetFolder
    {
        $id = $this->input($key);

        if ($id === null || $id === '') {
            return null;
        }

        return AssetFolder::query()
            ->where('workspace_id', $workspace->id)
            ->find((int) $id);
    }

    /**
     * Whether the request said anything at all about where this belongs.
     */
    protected function hasFolderKey(string $key): bool
    {
        return $this->exists($key);
    }

    /**
     * A rule confirming the named folder is one of this workspace's.
     *
     * A closure rather than `Rule::exists(...)->where(...)` only because the
     * accompanying accessor above has to do the same lookup anyway, and the two
     * agreeing by construction is worth more than the rule being declarative.
     */
    protected function folderBelongsToWorkspace(Workspace $workspace): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
            if ($value === null || $value === '') {
                return;
            }

            $exists = AssetFolder::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((int) $value)
                ->exists();

            if (! $exists) {
                $fail('That folder is not part of this workspace.');
            }
        };
    }

    /**
     * A rule refusing a folder name already used by one of its siblings.
     *
     * Case-insensitively, matching the partial unique indexes on the table:
     * "Brand" and "brand" are the same shelf to everybody except the database,
     * and a library containing both is one nobody trusts. The database is still
     * the authority — this exists so the answer arrives as a message on the
     * field instead of a 500 from a constraint violation.
     *
     * @param  int|null  $ignoreId  The folder being renamed, which is allowed to
     *                              keep its own name.
     */
    protected function uniqueAmongSiblings(Workspace $workspace, ?int $parentId, ?int $ignoreId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workspace, $parentId, $ignoreId): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $taken = AssetFolder::query()
                ->where('workspace_id', $workspace->id)
                ->when(
                    $parentId === null,
                    fn ($query) => $query->whereNull('parent_id'),
                    fn ($query) => $query->where('parent_id', $parentId),
                )
                ->whereRaw('lower(name) = lower(?)', [trim($value)])
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail('There is already a folder with that name here.');
            }
        };
    }
}
