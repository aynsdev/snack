<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\UpdateWorkspaceLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\WorkspaceLogoRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The workspace logo, a multipart upload kept apart from the settings for the
 * reason the web app keeps them apart — see
 * {@see \App\Http\Controllers\Workspaces\LogoController}. Both answer with the
 * workspace, carrying its new `logo_url`.
 */
class LogoController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        WorkspaceLogoRequest $request,
        UpdateWorkspaceLogo $logos,
    ): WorkspaceResource {
        $image = $request->logo();

        abort_if($image === null, 422);

        if (! $logos->handle($this->context->workspace(), $request->user(), $image)) {
            throw ValidationException::withMessages([
                'logo' => 'That image could not be processed. Try a JPEG or PNG.',
            ]);
        }

        return new WorkspaceResource($this->context->workspace()->refresh());
    }

    public function destroy(Workspace $workspace, Request $request, UpdateWorkspaceLogo $logos): WorkspaceResource
    {
        $this->authorize('update', $workspace);

        $logos->remove($this->context->workspace(), $request->user());

        return new WorkspaceResource($this->context->workspace()->refresh());
    }
}
