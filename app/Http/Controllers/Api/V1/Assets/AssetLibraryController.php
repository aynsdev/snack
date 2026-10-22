<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetFolderResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The asset library: the company's own shelves.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Assets\AssetLibraryController},
 * whose listing lives in private methods and is therefore repeated here. Same
 * shape: the whole folder tree flat (the client assembles it), the open
 * folder and its breadcrumb, and a page of the open folder's *subtree* —
 * the whole library at the top. A `q` search spans the whole library
 * regardless of the folder. Cursor-paginated: pass `next_cursor` as `before`.
 * Filters: `q`, `kind`.
 */
class AssetLibraryController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Asset::class, $workspace]);

        return $this->library($workspace, $request, folder: null);
    }

    public function show(Workspace $workspace, AssetFolder $assetFolder, Request $request): JsonResponse
    {
        $this->authorize('view', $assetFolder);

        return $this->library($workspace, $request, $assetFolder);
    }

    private function library(Workspace $workspace, Request $request, ?AssetFolder $folder): JsonResponse
    {
        $user = $request->user();

        // Both resources ask the policy per row; the policy reads memberships.
        $user->loadMissing('workspaceMemberships');

        $perPage = (int) config('snack.assets.page_size', 60);

        $term = $request->string('q')->squish()->limit(120, '')->toString();
        $kind = $request->string('kind')->toString();
        $cursor = $request->integer('before') ?: null;

        $tree = AssetFolder::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['children', 'assets'])
            ->orderBy('name')
            ->get()
            ->each(fn (AssetFolder $node) => $node->setRelation('workspace', $workspace));

        $assets = $this->assets($workspace, $this->subtreeIds($tree, $folder), $term, $kind)
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $assets->count() > $perPage;

        $page = $assets->take($perPage)
            ->each(fn (Asset $asset) => $asset->setRelation('workspace', $workspace));

        return response()->json([
            'data' => [
                'folders' => AssetFolderResource::collection($tree)->resolve($request),
                'folder' => $folder === null
                    ? null
                    : (new AssetFolderResource(
                        $folder->loadCount(['children', 'assets'])->setRelation('workspace', $workspace),
                    ))->resolve($request),
                'breadcrumb' => $folder === null
                    ? []
                    : $folder->ancestors()
                        ->map(fn (AssetFolder $ancestor): array => [
                            'id' => $ancestor->id,
                            'name' => $ancestor->name,
                        ])
                        ->values()
                        ->all(),
                'assets' => AssetResource::collection($page->values())->resolve($request),
                'filters' => [
                    'q' => $term === '' ? null : $term,
                    'kind' => $kind === '' ? null : $kind,
                ],
                'next_cursor' => $hasMore ? $page->last()?->id : null,
                'abilities' => [
                    'create' => $user->can('create', [Asset::class, $workspace]),
                    'create_folder' => $user->can('create', [AssetFolder::class, $workspace]),
                ],
            ],
        ]);
    }

    /**
     * The open folder and everything beneath it, walked breadth-first over the
     * tree already in memory and bounded by {@see AssetFolder::MAX_DEPTH}.
     *
     * @param  Collection<int, AssetFolder>  $tree
     * @return array<int, int>|null
     */
    private function subtreeIds(Collection $tree, ?AssetFolder $folder): ?array
    {
        if ($folder === null) {
            return null;
        }

        $ids = [$folder->id];

        for ($depth = 0; $depth < AssetFolder::MAX_DEPTH; $depth++) {
            $next = $tree->whereIn('parent_id', $ids)->pluck('id')->diff($ids)->all();

            if ($next === []) {
                break;
            }

            $ids = [...$ids, ...$next];
        }

        return $ids;
    }

    /**
     * @param  array<int, int>|null  $folderIds
     * @return Builder<Asset>
     */
    private function assets(Workspace $workspace, ?array $folderIds, string $term, string $kind): Builder
    {
        return Asset::query()
            ->where('workspace_id', $workspace->id)
            // A search spans the library; browsing narrows it.
            ->when(
                $term === '',
                fn (Builder $query) => $folderIds === null
                    ? $query
                    : $query->whereIn('folder_id', $folderIds),
                fn (Builder $query) => $query
                    ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%'),
            )
            ->when($kind !== '', fn (Builder $query) => $query->ofKind($kind))
            ->with([
                'file',
                'creator:id,name,username,avatar_path,title,presence_status',
            ]);
    }
}
