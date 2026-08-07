<?php

namespace App\Actions\Attachments;

use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accepts one uploaded file and parks it, unattached, on the private disk.
 *
 * # Why the upload happens before the message exists
 *
 * A file is uploaded while the composer is still open, so the progress bar can
 * run while the author keeps typing and so a 40 MB video is not re-sent every
 * time they fix a typo. The row therefore starts with a null `message_id` and is
 * claimed by {@see LinkAttachments} when the message is finally sent; anything
 * never claimed is swept by `snack:prune-uploads`.
 *
 * # Why the stored name is not the uploaded name
 *
 * The key is a ULID this application generates. Nothing the client sent is used
 * to build a path, which is what makes traversal (`../../.env`) and collisions
 * structurally impossible rather than a matter of escaping correctly. The name
 * the user typed survives in `original_name`, where it is data rather than a
 * filesystem instruction, and is what the download route sends back in the
 * Content-Disposition header.
 */
class StoreUpload
{
    /**
     * @param  Task|null  $task  The card this file belongs to, when it is being
     *                           attached to one. A card already exists when its
     *                           file is chosen, so there is nothing to claim
     *                           later and the row is never orphaned — which is
     *                           the whole difference from the composer's path.
     */
    public function handle(Workspace $workspace, User $uploader, UploadedFile $file, ?Task $task = null): MessageAttachment
    {
        $disk = (string) config('snack.uploads.disk', 'local');

        $path = Storage::disk($disk)->putFileAs(
            $this->directory($workspace),
            $file,
            Str::ulid()->toBase32().$this->extension($file),
        );

        $attachment = MessageAttachment::create([
            'workspace_id' => $workspace->id,
            'message_id' => null,
            'task_id' => $task?->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'path' => (string) $path,
            'original_name' => $this->safeName($file),
            /*
             * The *detected* type, not the one the client declared. A browser
             * sends whatever it likes in the multipart part, and this value ends
             * up in a Content-Type header on the way back out — so it is read
             * from the file's own bytes.
             */
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'metadata' => [],
        ]);

        // Dimensions and the thumbnail are derived on the queue: the response
        // this upload is blocking is a progress bar, and decoding a 20-megapixel
        // photo in it would stall the composer.
        ProcessAttachment::dispatch($attachment->id);

        return $attachment;
    }

    /**
     * Sharded by month so no single directory accumulates every file a busy
     * workspace has ever sent — some filesystems degrade badly past a few tens
     * of thousands of entries in one directory.
     */
    private function directory(Workspace $workspace): string
    {
        return sprintf('workspaces/%d/attachments/%s', $workspace->id, now()->format('Y/m'));
    }

    /**
     * A short, lowercase extension, or none at all.
     *
     * Only ever appended to a ULID the server generated, so this is cosmetic —
     * it makes a file on disk identifiable during an incident. It is still
     * filtered down to letters and digits, because it is the one part of the key
     * derived from client input.
     */
    private function extension(UploadedFile $file): string
    {
        $extension = strtolower((string) preg_replace(
            '/[^a-z0-9]/i', '', $file->getClientOriginalExtension(),
        ));

        return $extension === '' ? '' : '.'.substr($extension, 0, 12);
    }

    /**
     * The display name, stripped of anything that would let it act as a path.
     *
     * This string is echoed back in a Content-Disposition header and rendered in
     * the file browser, so it is normalized once here rather than at each of
     * those points.
     */
    private function safeName(UploadedFile $file): string
    {
        $name = str($file->getClientOriginalName())
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->replace(['/', '\\'], '-')
            ->trim()
            ->limit(180, '')
            ->toString();

        return $name === '' ? 'file' : $name;
    }
}
