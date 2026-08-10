<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\StoreAsset;
use App\Actions\Assets\UpdateAsset;
use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Things on the shelves.
 *
 * # Why uploading here is a normal form submission
 *
 * The composer uploads over XHR and answers with JSON, because the file is going
 * into a tray beside a message that does not exist yet and the page must not
 * move while the author is typing. Nothing here is like that: the person has
 * chosen a shelf and pressed a button, the thing they are looking at is a list
 * that now has one more row in it, and a redirect back to that list is both the
 * confirmation and the refresh. One request, one outcome, and the browser
 * handles the progress.
 */
class AssetController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        StoreAssetRequest $request,
        StoreAsset $assets,
    ): RedirectResponse {
        $file = $request->upload();

        abort_if($file === null, 422);

        $asset = $assets->handle(
            workspace: $workspace,
            curator: $request->user(),
            file: $file,
            folder: $request->folder($this->context),
            name: $request->input('name'),
            description: $request->input('description'),
        );

        return back()->with('success', "{$asset->name} added to the library.");
    }

    /**
     * Re-label an asset, or move it to another shelf.
     */
    public function update(
        Workspace $workspace,
        Asset $asset,
        UpdateAssetRequest $request,
        UpdateAsset $assets,
    ): RedirectResponse {
        $assets->handle($asset, [
            ...($request->has('name') ? ['name' => $request->string('name')->squish()->toString()] : []),
            ...($request->exists('description') ? ['description' => $request->input('description')] : []),
        ]);

        if ($request->movesAsset()) {
            $assets->move($asset, $request->destination($this->context));
        }

        return back()->with('success', 'Asset updated.');
    }

    /**
     * Take something off the shelf.
     *
     * Audited for the reason a folder deletion is: it is the one change here
     * that leaves nothing behind to look at. The file name is recorded
     * alongside the library's own name for it, because six months later those
     * are two different clues and whoever is asking has only one of them.
     */
    public function destroy(
        Workspace $workspace,
        Asset $asset,
        Request $request,
        DeleteAsset $assets,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $this->authorize('delete', $asset);

        $folderId = $asset->folder_id;

        $audit->handle(
            workspace: $workspace,
            action: AuditAction::AssetDeleted,
            actor: $request->user(),
            auditable: $asset,
            oldValues: [
                'name' => $asset->name,
                'folder_id' => $folderId,
                'file_name' => $asset->file?->original_name,
            ],
        );

        $assets->handle($asset);

        return back()->with('success', 'Asset deleted.');
    }
}
