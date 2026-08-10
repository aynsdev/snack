<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetFolder;

/**
 * Re-labels an asset, or moves it to another shelf.
 *
 * Only the library's own fields. The file underneath is deliberately untouchable
 * from here: replacing the bytes behind a name people have already linked to is
 * a different, riskier operation than correcting a typo in that name, and
 * conflating the two would mean every rename request was also a request that
 * could swap the company logo.
 */
class UpdateAsset
{
    /**
     * @param  array{name?: string, description?: string|null}  $attributes
     */
    public function handle(Asset $asset, array $attributes): Asset
    {
        $asset->fill(array_intersect_key($attributes, array_flip(['name', 'description'])));
        $asset->save();

        return $asset->refresh();
    }

    /**
     * Move an asset to another shelf, `null` meaning the top of the library.
     *
     * Separate from {@see handle()} for the reason a folder's move is: a field
     * that was not sent must not be read as "move this to the root". The
     * destination arrives resolved through the workspace, so this cannot file an
     * asset into another tenant's tree.
     */
    public function move(Asset $asset, ?AssetFolder $destination): Asset
    {
        $asset->folder_id = $destination?->id;
        $asset->save();

        return $asset->refresh();
    }
}
