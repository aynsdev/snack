<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\CreateAssetFolder;
use App\Actions\Assets\DeleteAssetFolder;
use App\Actions\Assets\UpdateAssetFolder;
use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\StoreAssetFolderRequest;
use App\Http\Requests\Assets\UpdateAssetFolderRequest;
use App\Models\AssetFolder;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The shelves.
 *
 * Every action declares {workspace} first, matching the route's parameter order
 * — Laravel passes route parameters positionally, so omitting it on a nested
 * route shifts every subsequent argument by one.
 *
 * Responses are redirects rather than JSON: a folder change rearranges the tree
 * the whole page is drawn from, so the page is what has to come back. That is
 * the opposite of the composer's upload endpoints, which answer with JSON
 * because the tray is client-owned state.
 */
class AssetFolderController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        StoreAssetFolderRequest $request,
        CreateAssetFolder $folders,
    ): RedirectResponse {
        $parent = $request->parent($this->context);

        $folder = $folders->handle(
            workspace: $workspace,
            creator: $request->user(),
            name: $request->string('name')->squish()->toString(),
            description: $request->input('description'),
            parent: $parent,
        );

        return to_route('assets.folders.show', [
            'workspace' => $workspace->slug,
            'assetFolder' => $folder->id,
        ])->with('success', "{$folder->name} created.");
    }

    /**
     * Rename a folder, rewrite its description, move it, or all three.
     *
     * The move is applied separately and only when `parent_id` was actually
     * sent — see {@see UpdateAssetFolderRequest}. A rename form that omits the
     * field must not be read as a request to move the folder to the top of the
     * library.
     */
    public function update(
        Workspace $workspace,
        AssetFolder $assetFolder,
        UpdateAssetFolderRequest $request,
        UpdateAssetFolder $folders,
    ): RedirectResponse {
        $folders->handle($assetFolder, [
            ...($request->has('name') ? ['name' => $request->string('name')->squish()->toString()] : []),
            ...($request->exists('description') ? ['description' => $request->input('description')] : []),
        ]);

        if ($request->movesFolder()) {
            $folders->move($assetFolder, $request->destination($this->context));
        }

        return back()->with('success', 'Folder updated.');
    }

    /**
     * Delete a folder and everything filed under it.
     *
     * Audited, unlike creating or renaming one. A deletion is the only change
     * here that leaves nothing behind to look at: the shelf is simply gone, and
     * "who removed the folder the contracts were in" is a question somebody
     * arrives with whole. Written from the controller rather than the action for
     * the reason every other audit entry is — the action is also reachable from
     * places with no actor to record.
     */
    public function destroy(
        Workspace $workspace,
        AssetFolder $assetFolder,
        Request $request,
        DeleteAssetFolder $folders,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $this->authorize('delete', $assetFolder);

        $parentId = $assetFolder->parent_id;

        $audit->handle(
            workspace: $workspace,
            action: AuditAction::AssetFolderDeleted,
            actor: $request->user(),
            auditable: $assetFolder,
            oldValues: [
                'name' => $assetFolder->name,
                'parent_id' => $parentId,
                'asset_count' => $assetFolder->assets()->count(),
            ],
        );

        $folders->handle($assetFolder);

        // Back to where the folder was, not to wherever the request came from:
        // the page it came from no longer exists.
        return $parentId === null
            ? to_route('assets.index', ['workspace' => $workspace->slug])
                ->with('success', 'Folder deleted.')
            : to_route('assets.folders.show', ['workspace' => $workspace->slug, 'assetFolder' => $parentId])
                ->with('success', 'Folder deleted.');
    }
}
