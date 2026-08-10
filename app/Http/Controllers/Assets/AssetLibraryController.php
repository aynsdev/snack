<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetFolderResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\Workspace;
use App\Policies\AssetPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The asset library: where a company keeps the things it needs to be able to
 * find again.
 *
 * # Why this is not the Files browser with folders bolted on
 *
 * Files lists what was *said* — every attachment on every message the viewer can
 * read, newest first, filtered through the container policies. It is a search
 * over conversation history, and it is flat because a message attachment is not
 * filed anywhere.
 *
 * This lists what was *put somewhere*. A brand kit, the signed contract, the
 * company one-pager: things that were deliberately placed so that somebody who
 * was not in the room can find them a year later. That is a different question
 * with a different answer, and the two would ruin each other if merged — a
 * library buried under every screenshot anybody ever pasted into #random is not
 * a library.
 *
 * The visibility rule is correspondingly simpler. There are no private shelves,
 * so there is no per-row container check: every member sees all of it, and
 * guests see none of it. That is the whole of {@see AssetPolicy}.
 *
 * # What one page load costs
 *
 * The folder tree is sent whole, flat, with counts — it is the navigation, so it
 * has to be complete, and it is bounded by how many shelves people make rather
 * than by how much they store. The assets are only ever one shelf's worth, and
 * cursor-paginated on top of that for the same reason the timeline is: things
 * keep being added while somebody is browsing, and an OFFSET would duplicate or
 * skip rows every time one was.
 */
class AssetLibraryController extends Controller
{
    /**
     * The top of the library.
     */
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $this->authorize('viewAny', [Asset::class, $workspace]);

        return $this->render($workspace, $request, folder: null);
    }

    /**
     * One shelf.
     */
    public function show(Workspace $workspace, AssetFolder $assetFolder, Request $request): InertiaResponse
    {
        /*
         * The parameter is {assetFolder} rather than {folder} so the group's
         * scopeBindings() resolves it through Workspace::assetFolders(): a
         * folder id from another tenant 404s at the router, before any of this
         * runs. All that is left to ask is whether this viewer may read the
         * library at all.
         */
        $this->authorize('view', $assetFolder);

        return $this->render($workspace, $request, $assetFolder);
    }

    private function render(Workspace $workspace, Request $request, ?AssetFolder $folder): InertiaResponse
    {
        $user = $request->user();

        /*
         * Both resources ask the policy what this viewer may do with each row,
         * and the policy answers by reading their membership. Loading it once
         * here is the difference between one query and one per folder plus one
         * per asset — the same fix ProjectController::index makes for the same
         * reason.
         */
        $user->loadMissing('workspaceMemberships');

        $perPage = (int) config('snack.assets.page_size', 60);

        $term = $request->string('q')->squish()->limit(120, '')->toString();
        $kind = $request->string('kind')->toString();
        $cursor = $request->integer('before') ?: null;

        $assets = $this->assets($workspace, $folder, $term, $kind)
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            // One extra row answers "is there more?" without a second COUNT.
            ->limit($perPage + 1)
            ->get();

        $hasMore = $assets->count() > $perPage;

        /*
         * Hand every row the workspace instance we already have.
         *
         * Both resources ask the policy what this viewer may do, and both
         * policies answer by reading the row's workspace — a lazy load per tile
         * otherwise, which is how a shelf of thirty things became thirty extra
         * queries. The same fix ProjectController::index makes, for the same
         * reason.
         */
        $page = $assets->take($perPage)
            ->each(fn (Asset $asset) => $asset->setRelation('workspace', $workspace));

        return Inertia::render('assets/index', [
            /*
             * The whole tree, flat, on every load. It is the navigation — the
             * sidebar draws it and the move dialog offers every node in it as a
             * destination — so a partial tree would be a sidebar that changes
             * shape as you walk it.
             */
            'folders' => AssetFolderResource::collection(
                AssetFolder::query()
                    ->where('workspace_id', $workspace->id)
                    ->withCount(['children', 'assets'])
                    ->orderBy('name')
                    ->get()
                    ->each(fn (AssetFolder $node) => $node->setRelation('workspace', $workspace)),
            )->resolve($request),

            'folder' => $folder === null
                ? null
                : (new AssetFolderResource(
                    $folder->loadCount(['children', 'assets'])->setRelation('workspace', $workspace),
                ))->resolve($request),

            /*
             * Outermost first, so the client renders the breadcrumb without
             * walking the flat tree itself — and so a folder whose ancestry is
             * somehow broken shows a short breadcrumb rather than hanging.
             */
            'breadcrumb' => $folder === null
                ? []
                : $folder->ancestors()
                    ->map(fn (AssetFolder $ancestor): array => [
                        'id' => $ancestor->id,
                        'name' => $ancestor->name,
                    ])
                    ->values()
                    ->all(),

            'assets' => Inertia::merge(
                AssetResource::collection($page->values())->resolve($request),
            ),

            'filters' => [
                'q' => $term === '' ? null : $term,
                'kind' => $kind === '' ? null : $kind,
            ],

            'next_cursor' => $hasMore ? $page->last()?->id : null,

            'abilities' => [
                'create' => $user->can('create', [Asset::class, $workspace]),
                'create_folder' => $user->can('create', [AssetFolder::class, $workspace]),
            ],
        ]);
    }

    /**
     * @return Builder<Asset>
     */
    private function assets(Workspace $workspace, ?AssetFolder $folder, string $term, string $kind): Builder
    {
        return Asset::query()
            ->where('workspace_id', $workspace->id)
            /*
             * A search spans the library; browsing does not.
             *
             * Someone typing a name is asking "where is the brand book", and
             * confining that to the shelf they happen to be standing in front of
             * answers a question nobody asked — they are searching precisely
             * because they do not know which shelf it is on. Clearing the
             * folder constraint is what makes the search box useful rather than
             * a filter on twelve visible tiles.
             */
            ->when(
                $term === '',
                fn (Builder $query) => $folder === null
                    ? $query->atRoot()
                    : $query->where('folder_id', $folder->id),
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
