<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\StoreAsset;
use App\Actions\Assets\UpdateAsset;
use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Things on the shelves.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Assets\AssetController}.
 */
class AssetController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * Multipart: `file` (required), `name`, `description`, `folder_id`.
     */
    public function store(Workspace $workspace, StoreAssetRequest $request, StoreAsset $assets): JsonResponse
    {
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

        return $this->resource($workspace, $asset, $request)->response()->setStatusCode(201);
    }

    /**
     * Re-label an asset, or move it: `folder_id` moves it only when sent, and
     * null moves it to the top of the library.
     */
    public function update(
        Workspace $workspace,
        Asset $asset,
        UpdateAssetRequest $request,
        UpdateAsset $assets,
    ): AssetResource {
        $assets->handle($asset, [
            ...($request->has('name') ? ['name' => $request->string('name')->squish()->toString()] : []),
            ...($request->exists('description') ? ['description' => $request->input('description')] : []),
        ]);

        if ($request->movesAsset()) {
            $assets->move($asset, $request->destination($this->context));
        }

        return $this->resource($workspace, $asset, $request);
    }

    /**
     * Audited, as on the web: a deletion is the one change that leaves
     * nothing behind to look at.
     */
    public function destroy(
        Workspace $workspace,
        Asset $asset,
        Request $request,
        DeleteAsset $assets,
        RecordAuditLog $audit,
    ): Response {
        $this->authorize('delete', $asset);

        $audit->handle(
            workspace: $workspace,
            action: AuditAction::AssetDeleted,
            actor: $request->user(),
            auditable: $asset,
            oldValues: [
                'name' => $asset->name,
                'folder_id' => $asset->folder_id,
                'file_name' => $asset->file?->original_name,
            ],
        );

        $assets->handle($asset);

        return response()->noContent();
    }

    private function resource(Workspace $workspace, Asset $asset, Request $request): AssetResource
    {
        $request->user()->loadMissing('workspaceMemberships');

        return new AssetResource(
            $asset->refresh()->load(['file', 'creator'])->setRelation('workspace', $workspace),
        );
    }
}
