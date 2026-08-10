<?php

namespace App\Actions\Assets;

use App\Actions\Attachments\StoreUpload;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Puts a file on a shelf.
 *
 * # One request, unlike the composer
 *
 * A message attachment is uploaded first and claimed later, because the message
 * it belongs to does not exist yet — the author is still typing it. A library
 * asset has no such gap: the person has already decided this file is going in
 * the library and which shelf it goes on, so the row and the bytes are written
 * together and the file is owned from the moment it lands. Nothing is ever
 * unclaimed, so nothing here can be swept by `snack:prune-uploads` or stolen by
 * a crafted send.
 *
 * The order inside the transaction is forced: the attachment carries the
 * `asset_id`, so the asset has to exist before it can be pointed at.
 */
class StoreAsset
{
    public function __construct(private readonly StoreUpload $uploads) {}

    public function handle(
        Workspace $workspace,
        User $curator,
        UploadedFile $file,
        ?AssetFolder $folder = null,
        ?string $name = null,
        ?string $description = null,
    ): Asset {
        return DB::transaction(function () use ($workspace, $curator, $file, $folder, $name, $description): Asset {
            $asset = Asset::create([
                'workspace_id' => $workspace->id,
                'folder_id' => $folder?->id,
                'name' => $this->name($name, $file),
                'description' => $description,
                'created_by' => $curator->id,
            ]);

            $attachment = $this->uploads->handle($workspace, $curator, $file, asset: $asset);

            return $asset->setRelation('file', $attachment);
        });
    }

    /**
     * What the library calls this thing.
     *
     * The uploaded filename when nobody said otherwise, minus its extension —
     * "Brand guidelines" reads as a document, "Brand guidelines.pdf" reads as a
     * file listing, and the extension is still on the file itself for whoever
     * downloads it. The name is a display string in the library and never part
     * of a storage key, so unlike {@see StoreUpload::safeName()} there is
     * nothing here it could be made to do.
     */
    private function name(?string $name, UploadedFile $file): string
    {
        $given = str($name ?? '')->squish()->limit(200, '')->toString();

        if ($given !== '') {
            return $given;
        }

        $fallback = str($file->getClientOriginalName())
            ->beforeLast('.')
            ->squish()
            ->limit(200, '')
            ->toString();

        return $fallback === '' ? 'Untitled asset' : $fallback;
    }
}
