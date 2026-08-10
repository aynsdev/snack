<?php

namespace App\Actions\Assets;

use App\Actions\Attachments\DeleteUpload;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Takes something off the shelf, and the bytes with it.
 *
 * The attachment row would cascade away on its own when the asset went, which is
 * exactly the problem: a cascade cannot reach the disk, so the file would
 * outlive every row that knew where it was. {@see DeleteUpload} is given the
 * attachment first so the object goes before the pointer to it does — the same
 * order, and the same reason, as {@see DeleteAssetFolder}.
 */
class DeleteAsset
{
    public function __construct(private readonly DeleteUpload $uploads) {}

    public function handle(Asset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $file = $asset->file;

            if ($file !== null) {
                $this->uploads->handle($file);
            }

            $asset->delete();
        });
    }
}
