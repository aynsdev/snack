<?php

namespace App\Http\Resources;

use App\Models\AssetFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shelf, as a node in the library tree.
 *
 * Mirrors the `AssetFolder` type in resources/js/types/snack.ts.
 *
 * Sent flat, each node naming its parent, exactly as {@see ProjectGroupResource}
 * sends headings without their projects: the client assembles the tree. Nesting
 * children here would mean every folder appearing twice the moment anything
 * wanted a flat list — and the move dialog, which offers every folder in the
 * workspace as a destination, wants precisely that.
 *
 * @mixin AssetFolder
 */
class AssetFolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AssetFolder $folder */
        $folder = $this->resource;

        $user = $request->user();

        return [
            'id' => $folder->id,
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
            'description' => $folder->description,

            /*
             * What is on the shelf, as two numbers rather than as contents. A
             * folder tile says "4 folders · 12 files" without the page having
             * loaded any of them, which is what lets the tree be rendered whole
             * while only the open folder's assets are fetched.
             */
            'folder_count' => (int) ($folder->getAttribute('children_count') ?? 0),
            'asset_count' => (int) ($folder->getAttribute('assets_count') ?? 0),

            'can_manage' => $user?->can('update', $folder) ?? false,

            /*
             * Separate from `can_manage` because it is a narrower right: a
             * non-empty folder takes other people's work with it, so only an
             * admin may delete one. Asked of the policy rather than reasoned
             * about here — see AssetFolderPolicy::delete.
             */
            'can_delete' => $user?->can('delete', $folder) ?? false,
        ];
    }
}
