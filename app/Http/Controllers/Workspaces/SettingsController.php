<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The workspace's own settings: what it is called, how large it may grow, and
 * the two policies that decide who can get in and where they can go.
 *
 * Separate from {@see App\Http\Controllers\Notifications\NotificationPreferenceController},
 * which sits at a similar URL and is its opposite in every way that matters:
 * these are shared, admin-gated, and change the workspace for everyone; those
 * are private, ungated, and change nothing for anybody else.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function edit(Workspace $workspace, Request $request): InertiaResponse
    {
        $this->authorize('update', $workspace);

        return Inertia::render('workspace/settings', [
            'workspace' => (new WorkspaceResource($workspace))->resolve($request),

            'settings' => [
                'allow_invitations' => $workspace->allowsInvitations(),
                'allow_self_serve_joins' => $workspace->allowsSelfServeChannelJoins(),
            ],

            /*
             * The floor the member limit may be set to, so the form can say why
             * rather than only refusing on submit. The server re-derives it in
             * UpdateWorkspaceRequest regardless.
             */
            'member_count' => $workspace->members()->count(),

            // Deleting a workspace takes every message with it, so the control
            // is the owner's alone — see WorkspacePolicy::delete.
            'is_owner' => $workspace->isOwnedBy($request->user()),
        ]);
    }

    public function update(
        Workspace $workspace,
        UpdateWorkspaceRequest $request,
        UpdateWorkspace $updateWorkspace,
    ): RedirectResponse {
        $updateWorkspace->handle(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->workspaceAttributes(),
            settings: $request->workspaceSettings(),
        );

        return back()->with('success', 'Workspace settings saved.');
    }
}
