<?php

namespace App\Actions\Attachments;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an attachment and the bytes behind it.
 *
 * Used for two things: an author changing their mind in the composer, and the
 * scheduled sweep of uploads that were never sent. Both delete the row *and* the
 * file — an orphaned object on the disk is invisible to every query in the
 * application and would never be found again.
 *
 * The disk delete comes first and its failure is ignored on purpose. If the
 * object is already gone the row must still go, or the sweep will retry the same
 * doomed pair on every run forever.
 */
class DeleteUpload
{
    public function handle(MessageAttachment $attachment): void
    {
        $disk = Storage::disk($attachment->disk);

        $disk->delete($attachment->path);

        if ($attachment->thumbnail_path !== null) {
            $disk->delete($attachment->thumbnail_path);
        }

        $attachment->delete();
    }
}
