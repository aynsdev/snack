<?php

namespace App\Actions\Attachments;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes an attachment and the bytes behind it.
 *
 * Used for three things: an author changing their mind in the composer, the
 * scheduled sweep of uploads that were never sent, and taking something off a
 * shelf in the asset library. All three delete the row *and* the file — an
 * orphaned object on the disk is invisible to every query in the application and
 * would never be found again.
 *
 * # The row goes even when the object will not
 *
 * The disk delete comes first and its failure is deliberately swallowed. If the
 * object is already gone, or the bucket is unreachable, the row must still go —
 * otherwise the sweep retries the same doomed pair on every run forever, and a
 * library entry whose upload failed can never be removed by the person looking
 * at it.
 *
 * This used to be implicit: the disks were configured `'throw' => false`, so a
 * failed delete quietly returned false. That is no longer true — the Spaces
 * disks throw, because a silent *write* failure means a file that the UI claims
 * exists and does not. Delete wants the opposite behaviour from write, so it is
 * stated here in code rather than inherited from whichever disk the row happens
 * to name. A local disk and a bucket now behave identically.
 *
 * The failure is logged rather than ignored outright: an object that could not
 * be removed is a real cost somebody is paying for, and the path is the only
 * thing that would let them find it again.
 */
class DeleteUpload
{
    public function handle(MessageAttachment $attachment): void
    {
        $this->forget($attachment, $attachment->path);

        if ($attachment->thumbnail_path !== null) {
            $this->forget($attachment, $attachment->thumbnail_path);
        }

        $attachment->delete();
    }

    private function forget(MessageAttachment $attachment, string $path): void
    {
        try {
            Storage::disk($attachment->disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Could not delete a stored file; removing its row anyway.', [
                'attachment_id' => $attachment->id,
                'disk' => $attachment->disk,
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
