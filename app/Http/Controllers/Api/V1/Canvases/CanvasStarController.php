<?php

namespace App\Http\Controllers\Api\V1\Canvases;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileCanvasStarController;
use App\Models\Canvas;
use App\Models\FileCanvasStar;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Starring a canvas into the viewer's own sidebar.
 *
 * The JSON mirror of the canvas half of {@see FileCanvasStarController}.
 * Both verbs are idempotent and answer 204.
 */
class CanvasStarController extends Controller
{
    public function store(Workspace $workspace, Canvas $canvas, Request $request): Response
    {
        $this->authorize('view', $canvas);

        FileCanvasStar::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'canvas_id' => $canvas->id,
        ]);

        return response()->noContent();
    }

    public function destroy(Workspace $workspace, Canvas $canvas, Request $request): Response
    {
        $this->authorize('view', $canvas);

        FileCanvasStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->where('canvas_id', $canvas->id)
            ->delete();

        return response()->noContent();
    }
}
