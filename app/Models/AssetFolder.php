<?php

namespace App\Models;

use App\Actions\Assets\UpdateAssetFolder;
use App\Policies\AssetFolderPolicy;
use Database\Factories\AssetFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A shelf in the asset library.
 *
 * Folders nest, and a null `parent_id` means the top of the library rather than
 * a hidden root row — see the migration for why there is no synthetic root.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read AssetFolder|null $parent
 * @property-read Collection<int, AssetFolder> $children
 * @property-read Collection<int, Asset> $assets
 */
#[Fillable(['workspace_id', 'parent_id', 'name', 'description', 'created_by'])]
class AssetFolder extends Model
{
    /** @use HasFactory<AssetFolderFactory> */
    use HasFactory;

    /**
     * How deep the tree may go, counting the top level as depth 1.
     *
     * A bound rather than a preference. Every breadcrumb, every move, and every
     * descendant sweep walks this structure, and an unbounded tree is an
     * unbounded walk — including one a single crafted request could deepen a
     * thousand levels. Five is more nesting than any real library uses; past
     * "Brand / Logos / Social / Instagram" people search instead of clicking.
     */
    public const MAX_DEPTH = 5;

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<AssetFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AssetFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'folder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * The top of the library.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAtRoot(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    // -- State ---------------------------------------------------------------

    /**
     * This folder and every folder above it, outermost first.
     *
     * The breadcrumb, and also what {@see UpdateAssetFolder::move()} reads to
     * refuse a move into the folder's own subtree. Walked with a query
     * per level rather than a recursive CTE: the walk is bounded by
     * {@see MAX_DEPTH}, so it is at most five cheap primary-key lookups, and the
     * bound is what makes that acceptable.
     *
     * @return Collection<int, AssetFolder>
     */
    public function ancestors(): Collection
    {
        /** @var Collection<int, AssetFolder> $chain */
        $chain = new Collection;

        $folder = $this;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $chain->prepend($folder);

            $parent = $folder->parent_id === null ? null : $folder->parent;

            if ($parent === null) {
                break;
            }

            $folder = $parent;
        }

        return $chain;
    }

    /**
     * How far down the tree this folder sits, counting itself. 1 at the top.
     */
    public function depth(): int
    {
        return $this->ancestors()->count();
    }

    /**
     * Whether there is anything on this shelf, directly.
     *
     * Prefers counts the caller already loaded, and this is not a micro-
     * optimization: {@see AssetFolderPolicy::delete()} asks this
     * of every folder it decides about, so a version that always queried would
     * put two round trips per row behind a tree the sidebar renders on every
     * page. The library's queries load `withCount(['children', 'assets'])` for
     * exactly this reason — the UI wants the numbers anyway.
     */
    public function isEmpty(): bool
    {
        $children = $this->getAttribute('children_count');
        $assets = $this->getAttribute('assets_count');

        if ($children !== null && $assets !== null) {
            return (int) $children === 0 && (int) $assets === 0;
        }

        return $this->children()->doesntExist() && $this->assets()->doesntExist();
    }
}
