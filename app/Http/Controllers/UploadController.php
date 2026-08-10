<?php

namespace App\Http\Controllers;

use App\Actions\Attachments\DeleteUpload;
use App\Actions\Attachments\StoreUpload;
use App\Http\Requests\Uploads\StoreUploadRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The composer's attachment tray.
 *
 * Files are uploaded one request at a time while the composer is still open, so
 * each has its own progress bar and a 40 MB video is not re-sent every time the
 * author fixes a typo. Nothing here creates a message; these endpoints only park
 * bytes and hand back an id the send endpoint can claim.
 *
 * Answers with JSON rather than an Inertia response for the same reason the
 * message endpoints do: the tray is client-owned state that changes several
 * times between page visits.
 */
class UploadController extends Controller
{
    public function store(
        Workspace $workspace,
        StoreUploadRequest $request,
        StoreUpload $uploads,
    ): JsonResponse {
        $file = $request->upload();

        abort_if($file === null, 422);

        $attachment = $uploads->handle($workspace, $request->user(), $file);

        return response()->json(
            ['attachment' => AttachmentResource::payload($attachment)],
            201,
        );
    }

    /**
     * Discard an upload the author changed their mind about.
     *
     * Only ever the uploader's own, and only while it is still unattached: once
     * a file is on a sent message it is part of that message, and removing it is
     * deleting the message rather than editing the tray. The two conditions are
     * a 404 rather than a 403 — the id space is shared across the workspace, and
     * "that file exists but is not yours" is not something to confirm.
     */
    public function destroy(
        Workspace $workspace,
        MessageAttachment $attachment,
        Request $request,
        DeleteUpload $uploads,
    ): JsonResponse {
        abort_unless(
            $attachment->message_id === null
                // A file on a card is not tray state either: it is removed
                // through the card, where the project's policy applies.
                && $attachment->task_id === null
                // Nor is a file in the asset library, which is removed through
                // the asset — otherwise whoever uploaded the company logo could
                // delete it out from under the library by hitting this route
                // with its id, and the library would be left holding a row with
                // no bytes behind it.
                && $attachment->asset_id === null
                // Canvas covers are document state, removed from the Canvas.
                && $attachment->canvas_id === null
                && $attachment->uploaded_by === $request->user()->id,
            404,
        );

        $uploads->handle($attachment);

        return response()->json(['deleted' => true]);
    }
}
