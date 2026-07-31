<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\UpdateWorkspaceLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\WorkspaceLogoRequest;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The workspace logo.
 *
 * On its own endpoints rather than folded into the settings form, matching
 * {@see App\Http\Controllers\Settings\AvatarController}: a file needs a
 * multipart request, and merging the two would make every rename one as well —
 * and would re-validate the member limit every time somebody changed a picture.
 *
 * Both redirect back rather than answering with JSON, because the new URL has to
 * reach the workspace rail, which reads it from the shared `snack` prop.
 */
class LogoController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        WorkspaceLogoRequest $request,
        UpdateWorkspaceLogo $logos,
    ): RedirectResponse {
        $image = $request->logo();

        abort_if($image === null, 422);

        if (! $logos->handle($this->context->workspace(), $request->user(), $image)) {
            /*
             * Decoding failed despite passing validation — a truncated file, or
             * a format this build of GD cannot read. Reported on the field so it
             * lands next to the control the person just used.
             */
            return back()->withErrors([
                'logo' => 'That image could not be processed. Try a JPEG or PNG.',
            ]);
        }

        return back()->with('success', 'Workspace logo updated.');
    }

    public function destroy(
        Workspace $workspace,
        Request $request,
        UpdateWorkspaceLogo $logos,
    ): RedirectResponse {
        $this->authorize('update', $workspace);

        $logos->remove($this->context->workspace(), $request->user());

        return back()->with('success', 'Workspace logo removed.');
    }
}
