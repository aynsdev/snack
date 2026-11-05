<?php

namespace App\Actions\Attachments;

use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts copies of a message's files on a card — how a task filed from a chat
 * message keeps the screenshot the message was about.
 *
 * # Why the bytes are copied, not shared
 *
 * A row hangs off one owner (the `message_attachments_one_owner_check`
 * constraint), and {@see DeleteUpload} removes a row's object along with it.
 * Two rows naming one key would let deleting the message take the card's file
 * with it, or the other way round. So each copy gets its own key, minted the
 * way {@see StoreUpload} mints one, and the two lead separate lives.
 *
 * The caller decides which files may be copied: this trusts that whoever the
 * card is filed for could already read them.
 */
class CopyAttachmentsToTask
{
    /**
     * @param  Collection<int, MessageAttachment>  $files
     * @return Collection<int, MessageAttachment> The copies that were made.
     */
    public function handle(Task $task, int $uploadedBy, Collection $files): Collection
    {
        return $files
            ->map(fn (MessageAttachment $file): ?MessageAttachment => $this->copy($task, $uploadedBy, $file))
            ->filter()
            ->values();
    }

    /**
     * One file, or nothing when its object cannot be copied.
     *
     * A missing object is reported and skipped rather than failing the card:
     * the task is what was asked for, and a file that is already gone from the
     * disk cannot be rescued by refusing to file it.
     */
    private function copy(Task $task, int $uploadedBy, MessageAttachment $file): ?MessageAttachment
    {
        $disk = Storage::disk($file->disk);
        $path = $this->siblingKey($file->path);

        try {
            $disk->copy($file->path, $path);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $thumbnail = null;

        if ($file->thumbnail_path !== null) {
            $thumbnail = $this->siblingKey($file->thumbnail_path);

            try {
                $disk->copy($file->thumbnail_path, $thumbnail);
            } catch (Throwable $exception) {
                report($exception);
                $thumbnail = null;
            }
        }

        $copy = MessageAttachment::create([
            'workspace_id' => $file->workspace_id,
            'message_id' => null,
            'task_id' => $task->id,
            'uploaded_by' => $uploadedBy,
            'disk' => $file->disk,
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'metadata' => $file->metadata,
        ]);

        // An image whose thumbnail did not come across gets one derived, the
        // same as a fresh upload.
        if ($thumbnail === null && $copy->isImage()) {
            ProcessAttachment::dispatch($copy->id);
        }

        return $copy;
    }

    /**
     * A fresh server-generated key in the same directory, keeping the
     * extension so the object is still identifiable on the disk.
     */
    private function siblingKey(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return ($directory === '.' ? '' : $directory.'/')
            .Str::ulid()->toBase32()
            .($extension === '' ? '' : '.'.$extension);
    }
}
