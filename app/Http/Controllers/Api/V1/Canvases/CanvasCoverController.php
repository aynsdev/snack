<?php

namespace App\Http\Controllers\Api\V1\Canvases;

use App\Actions\Attachments\DeleteUpload;
use App\Actions\Attachments\StoreUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCanvasCoverRequest;
use App\Http\Resources\CanvasResource;
use App\Models\Canvas;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * A canvas's cover image. Setting one replaces whatever was there.
 *
 * The JSON mirror of {@see \App\Http\Controllers\CanvasCoverController}.
 */
class CanvasCoverController extends Controller
{
    /**
     * Answers with the canvas, so the app has the new `cover_url` at once.
     */
    public function store(
        Workspace $workspace,
        Canvas $canvas,
        StoreCanvasCoverRequest $request,
        StoreUpload $uploads,
        DeleteUpload $deletions,
    ): CanvasResource {
        $cover = $request->cover();

        abort_if($cover === null, 422);

        if ($canvas->cover !== null) {
            $deletions->handle($canvas->cover);
        }

        $uploads->handle(
            workspace: $workspace,
            uploader: $request->user(),
            file: $cover,
            canvas: $canvas,
        );

        $request->user()->loadMissing('workspaceMemberships');

        return new CanvasResource(
            $canvas->load(['creator', 'editor', 'cover', 'channel', 'conversation.activeUsers'])
                ->loadExists([
                    'stars as is_starred' => fn (Builder $query) => $query->where('user_id', $request->user()->id),
                ])
                ->setRelation('workspace', $workspace),
        );
    }

    public function destroy(Workspace $workspace, Canvas $canvas, DeleteUpload $deletions): Response
    {
        $this->authorize('update', $canvas);

        if ($canvas->cover !== null) {
            $deletions->handle($canvas->cover);
        }

        return response()->noContent();
    }
}
