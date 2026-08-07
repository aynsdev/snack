<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Attachments\DeleteUpload;
use App\Actions\Attachments\StoreUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\StoreUploadRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Files on a card.
 *
 * # One step, where the composer needs two
 *
 * A message's files are uploaded before the message exists and claimed when it
 * is sent. A card is already there when somebody drops a file on it, so the
 * upload *is* the attach: one request, no unclaimed rows, nothing for the prune
 * sweep to reason about. Same validation, same disk, same download route — see
 * {@see StoreUpload}.
 *
 * # Removing
 *
 * The uploader, or a project lead. The same split as deleting a card or a
 * comment: somebody has to be able to take down a file put on the wrong card by
 * a person now on holiday, and unlike an edit it leaves no false record behind.
 */
class TaskAttachmentController extends Controller
{
    public function store(
        Workspace $workspace,
        Task $task,
        StoreUploadRequest $request,
        StoreUpload $uploads,
    ): JsonResponse {
        // Attaching a file is working the card, which is the same right as
        // moving it. The upload request itself only asks whether the bytes are
        // acceptable; it knows nothing about this project.
        $this->authorize('update', $task);

        $file = $request->upload();

        abort_if($file === null, 422);

        $attachment = $uploads->handle($workspace, $request->user(), $file, $task);

        return response()->json(
            ['attachment' => AttachmentResource::payload($attachment)],
            201,
        );
    }

    public function destroy(
        Workspace $workspace,
        Task $task,
        MessageAttachment $attachment,
        Request $request,
        DeleteUpload $uploads,
    ): JsonResponse {
        // A 404 rather than a 403: attachment ids are shared across the
        // workspace, and "that file exists but is on another card" is not
        // something to confirm to somebody guessing.
        abort_unless($attachment->task_id === $task->id, 404);

        $this->authorize('update', $task);

        $user = $request->user();

        abort_unless(
            $attachment->uploaded_by === $user->id || $user->can('update', $task->project),
            403,
        );

        $uploads->handle($attachment);

        return response()->json(['deleted' => true]);
    }
}
