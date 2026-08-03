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
        //
        // `addressed()` excludes the shareable link, which shares this table but
        // is not a row in this list — it is a single URL with its own card, and
        // listing it among the people who were written to would make "revoke"
        // ambiguous about what it closes.
        $invitations = $workspace->invitations()
            ->addressed()
            ->outstanding()
            ->with('inviter')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $link = $workspace->invitations()
            ->links()
            ->outstanding()
            ->with('inviter')
            ->latest()
            ->first();

        return Inertia::render('workspace/invitations', [
            'invitations' => WorkspaceInvitationResource::collection($invitations),

            /*
             * Null when the workspace has no live link, which the page renders
             * as an off state with a button rather than as an empty card. An
             * expired or spent link is still returned — the admin needs to see
             * *why* it stopped working, and "nothing here" would read as though
             * one was never made.
             */
            'inviteLink' => $link === null
                ? null
                : (new WorkspaceInvitationResource($link))->withUrl()->resolve(request()),
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
