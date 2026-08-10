<?php

namespace App\Actions\Assets;

use App\Actions\Attachments\DeleteUpload;
use App\Models\AssetFolder;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;

/**
 * Removes a shelf and everything filed under it.
 *
 * # Why the disk is swept before the rows are dropped
 *
 * The foreign keys cascade: deleting a folder takes its subfolders, their
 * assets, and those assets' attachment rows with it, in one statement. What a
 * cascade cannot do is delete anything outside the database, so the bytes would
 * survive every row that knew where they were — an object no query in the
 * application can reach, on a disk somebody pays for, forever. The orphan sweep
 * would not find them either; it reads rows, and the rows are gone.
 *
 * So the files are collected and deleted first, and only then is the cascade
 * allowed to run. Both halves are one transaction: a crash between them would
 * otherwise leave the library holding entries whose bytes are already gone,
 * which is the worse of the two failures — a missing object is invisible, a
 * broken download is not.
 */
class DeleteAssetFolder
{
    public function __construct(private readonly DeleteUpload $uploads) {}

    public function handle(AssetFolder $folder): void
    {
        DB::transaction(function () use ($folder): void {
            $folderIds = $this->subtreeIds($folder);

            MessageAttachment::query()
                ->whereHas('asset', fn ($asset) => $asset->whereIn('folder_id', $folderIds))
                /*
                 * Chunked by id rather than paged: the loop deletes the rows it
                 * is iterating, and an OFFSET would skip half of them as the
                 * result set shrank underneath it — the same reason
                 * `snack:prune-uploads` chunks.
                 */
                ->chunkById(200, function ($attachments): void {
                    foreach ($attachments as $attachment) {
                        $this->uploads->handle($attachment);
                    }
                });

            $folder->delete();
        });
    }

    /**
     * This folder's id and every id below it.
     *
     * A level-at-a-time walk rather than a recursive CTE, for the reason
     * {@see AssetFolder::ancestors()} walks rather than recursing: the tree is
     * bounded at {@see AssetFolder::MAX_DEPTH}, so this is a fixed handful of
     * indexed queries, and it stays readable to somebody who does not write
     * Postgres for a living.
     *
     * @return array<int, int>
     */
    private function subtreeIds(AssetFolder $folder): array
    {
        $ids = [$folder->id];
        $frontier = [$folder->id];

        for ($depth = 1; $depth < AssetFolder::MAX_DEPTH && $frontier !== []; $depth++) {
            /** @var array<int, int> $frontier */
            $frontier = AssetFolder::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
