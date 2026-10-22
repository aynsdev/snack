<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace's own settings — the mirror of
 * {@see \App\Http\Controllers\Workspaces\SettingsController}. A save answers
 * with the settings as they now stand, so the app needs no second request.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function edit(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('update', $workspace);

        return $this->settings($workspace, $request);
    }

    public function update(
        Workspace $workspace,
        UpdateWorkspaceRequest $request,
        UpdateWorkspace $updateWorkspace,
    ): JsonResponse {
        $updateWorkspace->handle(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->workspaceAttributes(),
            settings: $request->workspaceSettings(),
        );

        return $this->settings($this->context->workspace()->refresh(), $request);
    }

    /**
     * The same props the web page renders. `member_count` is the floor the
     * member limit may be set to; deleting the workspace is the owner's alone,
     * hence `is_owner`.
     */
    private function settings(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'workspace' => (new WorkspaceResource($workspace))->resolve($request),
                'settings' => [
                    'allow_invitations' => $workspace->allowsInvitations(),
                    'allow_self_serve_joins' => $workspace->allowsSelfServeChannelJoins(),
                ],
                'member_count' => $workspace->members()->count(),
                'is_owner' => $workspace->isOwnedBy($request->user()),
            ],
        ]);
    }
}
