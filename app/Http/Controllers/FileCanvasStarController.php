<?php

namespace App\Http\Controllers;

use App\Models\Canvas;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FileCanvasStarController extends Controller
{
    public function storeCanvas(
        Workspace $workspace,
        Canvas $canvas,
        Request $request,
    ): RedirectResponse {
        $this->authorize('view', $canvas);

        FileCanvasStar::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'canvas_id' => $canvas->id,
        ]);

        return back();
    }

    public function destroyCanvas(
        Workspace $workspace,
        Canvas $canvas,
        Request $request,
    ): RedirectResponse {
        $this->authorize('view', $canvas);

        FileCanvasStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->where('canvas_id', $canvas->id)
            ->delete();

        return back();
    }

    public function storeFile(
        Workspace $workspace,
        MessageAttachment $attachment,
        Request $request,
    ): RedirectResponse {
        $this->authorizeFile($workspace, $attachment);

        FileCanvasStar::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'message_attachment_id' => $attachment->id,
        ]);

        return back();
    }

    public function destroyFile(
        Workspace $workspace,
        MessageAttachment $attachment,
        Request $request,
    ): RedirectResponse {
        $this->authorizeFile($workspace, $attachment);

        FileCanvasStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->where('message_attachment_id', $attachment->id)
            ->delete();

        return back();
    }

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
