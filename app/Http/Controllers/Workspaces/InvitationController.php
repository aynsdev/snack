<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Actions\Workspaces\RevokeWorkspaceInvitation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Requests\Workspaces\StoreWorkspaceInvitationRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-side invitation management. Redeeming a link lives in
 * {@see InvitationAcceptanceController}.
 */
class InvitationController extends Controller
{
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [WorkspaceInvitation::class, $workspace]);

        // Outstanding rather than pending: an expired invitation stays listed
        // so an admin can see that someone was invited and never arrived.
        $invitations = $workspace->invitations()
            ->outstanding()
            ->with('inviter')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('workspace/invitations', [
            'invitations' => WorkspaceInvitationResource::collection($invitations),
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreWorkspaceInvitationRequest $request,
        InviteWorkspaceMember $inviteMember,
    ): RedirectResponse {
        $invitation = $inviteMember->handle(
            workspace: $workspace,
            inviter: $request->user(),
            email: $request->string('email')->toString(),
            role: $request->role(),
        );

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function destroy(
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        Request $request,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): RedirectResponse {
        $this->authorize('revoke', $invitation);

        $revokeInvitation->handle($invitation, $request->user());

        return back()->with('success', 'Invitation revoked.');
    }
}
