<?php

namespace App\Http\Controllers;

use App\Actions\Attachments\DeleteUpload;
use App\Actions\Attachments\StoreUpload;
use App\Http\Requests\StoreCanvasCoverRequest;
use App\Models\Canvas;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CanvasCoverController extends Controller
{
    public function store(
        Workspace $workspace,
        Canvas $canvas,
        StoreCanvasCoverRequest $request,
        StoreUpload $uploads,
        DeleteUpload $deletions,
    ): RedirectResponse {
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

        return back()->with('success', 'Canvas cover updated.');
    }

    public function destroy(
        Workspace $workspace,
        Canvas $canvas,
        Request $request,
        DeleteUpload $deletions,
    ): RedirectResponse {
        $this->authorize('update', $canvas);

        if ($canvas->cover !== null) {
            $deletions->handle($canvas->cover);
        }

        return back()->with('success', 'Canvas cover removed.');
    }
}
