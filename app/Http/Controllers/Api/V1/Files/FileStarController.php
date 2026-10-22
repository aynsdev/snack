<?php

namespace App\Http\Controllers\Api\V1\Files;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileCanvasStarController;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Starring a shared file into the viewer's own sidebar.
 *
 * The JSON mirror of the file half of {@see FileCanvasStarController}.
 * Both verbs are idempotent and answer 204.
 */
class FileStarController extends Controller
{
    public function store(Workspace $workspace, MessageAttachment $attachment, Request $request): Response
    {
        $this->authorizeFile($workspace, $attachment);

        FileCanvasStar::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'message_attachment_id' => $attachment->id,
        ]);

        return response()->noContent();
    }

    public function destroy(Workspace $workspace, MessageAttachment $attachment, Request $request): Response
    {
        $this->authorizeFile($workspace, $attachment);

        FileCanvasStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->where('message_attachment_id', $attachment->id)
            ->delete();

        return response()->noContent();
    }

    /**
     * Only a file on a message can be starred, and only by someone who may
     * read that message — an unattached upload, a card's file or an asset is
     * a 404 here, as it is on the web.
     */
    private function authorizeFile(Workspace $workspace, MessageAttachment $attachment): void
    {
        abort_unless(
            $attachment->workspace_id === $workspace->id && $attachment->message_id !== null,
            404,
        );

        $message = $attachment->message;

        abort_if($message === null, 404);

        Gate::authorize('view', $message);
    }
}
