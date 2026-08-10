<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A library entry as delivered to the client.
 *
 * Mirrors the `LibraryAsset` type in resources/js/types/snack.ts.
 *
 * # Two names, on purpose
 *
 * `name` is what the library calls it; `file.name` is what was uploaded. They
 * are usually different, and the gallery shows both — the first as the title,
 * the second in the detail panel, where somebody checking whether this is the
 * right file needs to see what it actually is.
 *
 * # The file is the attachment payload, unchanged
 *
 * Signed download and thumbnail URLs, the detected mime type, the dimensions the
 * queue derived — all of it comes from {@see AttachmentResource}, because the
 * bytes behind an asset are an attachment like any other. Re-deriving any of it
 * here would be a second implementation of the one thing in this application
 * that must not have two.
 *
 * Null while the row exists but the file does not, which is a state only a
 * partially-applied deletion can produce. The client renders it as a broken
 * entry rather than crashing, which is the honest thing to show.
 *
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;

        $file = $asset->file;
        $user = $request->user();

        return [
            'id' => $asset->id,
            'folder_id' => $asset->folder_id,
            'name' => $asset->name,
            'description' => $asset->description,

            'file' => $file === null ? null : AttachmentResource::payload($file),

            'creator' => $asset->creator === null
                ? null
                : (new UserResource($asset->creator))->resolve($request),

            'created_at' => $asset->created_at?->toIso8601String(),

            /*
             * Asked of the policy rather than recomputed from the role, so the
             * button the client draws and the answer the endpoint gives cannot
             * drift apart. Cheap because the viewer's memberships are eager
             * loaded once per request — see AssetLibraryController.
             */
            'can_manage' => $user?->can('update', $asset) ?? false,
        ];
    }
}
