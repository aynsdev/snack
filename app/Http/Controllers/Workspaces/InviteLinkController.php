<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\CreateWorkspaceInviteLink;
use App\Actions\Workspaces\RevokeWorkspaceInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreInviteLinkRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The workspace's shareable invite link.
 *
 * Separate from {@see InvitationController} because the two are different
 * objects to an admin even though they share a table: that one manages a list of
 * people who were each written to, this one manages a single URL that gets
 * pasted into an onboarding doc. Merging them would mean one screen's actions
 * silently operating on the other's rows.
 */
class InviteLinkController extends Controller
{
    /**
     * Create the link, or replace the existing one.
     *
     * `store` rather than `update` for regeneration too: regenerating does not
     * modify a link, it revokes one and issues another with a different token.
     * Treating that as an update would suggest the old URL still works.
     */
    public function store(
        Workspace $workspace,
        StoreInviteLinkRequest $request,
        CreateWorkspaceInviteLink $createLink,
    ): RedirectResponse {
        // Authorized by StoreInviteLinkRequest, on the same `inviteMembers`
        // permission that gates an emailed invitation.
        $createLink->handle(
            workspace: $workspace,
            creator: $request->user(),
            role: $request->role(),
            maxUses: $request->maxUses(),
        );

        /*
         * The plaintext token is deliberately *not* flashed into the session.
         * The invitations page reads the live link through
         * {@see InvitationController::index()}, which re-derives the URL from
         * the row — so a redirect, a refresh, or a second admin opening the page
         * all show the same thing, and the token does not end up in a session
         * store as a side effect of a redirect.
         */
        return back()->with('success', 'Invite link ready.');
    }

    /**
     * Turn the link off without issuing a replacement.
     */
    public function destroy(
        Workspace $workspace,
        Request $request,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): RedirectResponse {
        $link = $workspace->invitations()->links()->outstanding()->first();

        if ($link === null) {
            // Nothing to revoke: two admins clicking at once, or a stale tab.
            // Not an error — the desired state is the state.
            return back()->with('success', 'Invite link is already off.');
        }

        $this->authorize('revoke', $link);

        $revokeInvitation->handle($link, $request->user());

        return back()->with('success', 'Invite link turned off.');
    }
}
