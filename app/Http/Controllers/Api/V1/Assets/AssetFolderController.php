<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Actions\Assets\CreateAssetFolder;
use App\Actions\Assets\DeleteAssetFolder;
use App\Actions\Assets\UpdateAssetFolder;
use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\StoreAssetFolderRequest;
use App\Http\Requests\Assets\UpdateAssetFolderRequest;
use App\Http\Resources\AssetFolderResource;
use App\Models\AssetFolder;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The shelves.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Assets\AssetFolderController}.
 * A folder change rearranges the tree, so after any of these the app should
 * re-fetch the library listing rather than patch its local copy.
 */
class AssetFolderController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        StoreAssetFolderRequest $request,
        CreateAssetFolder $folders,
    ): JsonResponse {
        $folder = $folders->handle(
            workspace: $workspace,
            creator: $request->user(),
            name: $request->string('name')->squish()->toString(),
            description: $request->input('description'),
            parent: $request->parent($this->context),
        );

        return $this->resource($workspace, $folder, $request)->response()->setStatusCode(201);
    }

    /**
     * Rename, re-describe, move, or all three. `parent_id` moves the folder
     * only when it is sent; null moves it to the top of the library.
     */
    public function update(
        Workspace $workspace,
        AssetFolder $assetFolder,
        UpdateAssetFolderRequest $request,
        UpdateAssetFolder $folders,
    ): AssetFolderResource {
        $folders->handle($assetFolder, [
            ...($request->has('name') ? ['name' => $request->string('name')->squish()->toString()] : []),
            ...($request->exists('description') ? ['description' => $request->input('description')] : []),
        ]);

        if ($request->movesFolder()) {
            $folders->move($assetFolder, $request->destination($this->context));
        }

        return $this->resource($workspace, $assetFolder, $request);
    }

    /**
     * Deletes the folder and everything filed under it. Audited, as on the web.
     */
    public function destroy(
        Workspace $workspace,
        AssetFolder $assetFolder,
        Request $request,
        DeleteAssetFolder $folders,
        RecordAuditLog $audit,
    ): Response {
        $this->authorize('delete', $assetFolder);

        $audit->handle(
            workspace: $workspace,
            action: AuditAction::AssetFolderDeleted,
            actor: $request->user(),
            auditable: $assetFolder,
            oldValues: [
                'name' => $assetFolder->name,
                'parent_id' => $assetFolder->parent_id,
                'asset_count' => $assetFolder->assets()->count(),
            ],
        );

        $folders->handle($assetFolder);

        return response()->noContent();
    }

    private function resource(Workspace $workspace, AssetFolder $folder, Request $request): AssetFolderResource
    {
        $request->user()->loadMissing('workspaceMemberships');

        return new AssetFolderResource(
            $folder->refresh()->loadCount(['children', 'assets'])->setRelation('workspace', $workspace),
        );
    }
}
