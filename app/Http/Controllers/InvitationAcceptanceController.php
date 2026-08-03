<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Models\WorkspaceInvitation;
use App\Services\RegistrationGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeeming an invitation link.
 *
 * Reachable by guests: the token itself is the credential, and someone who was
 * invited needs to see what they were invited to before deciding to sign up.
 * The landing page therefore reveals only the workspace name and inviter.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly RegistrationGate $gate) {}

    public function show(string $token, Request $request): RedirectResponse|Response
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('auth/invitation-invalid', [
                'reason' => $invitation?->status() ?? 'invalid',
            ]);
        }

        $invitation->load(['workspace', 'inviter']);

        // Already inside: there is nothing left to accept, so skip the landing
        // page rather than offering a button that the policy would refuse.
        if ($request->user()?->belongsToWorkspace($invitation->workspace) === true) {
            $this->gate->forget();

            return to_route('workspaces.show', ['workspace' => $invitation->workspace->slug]);
        }

        // Stash the token so registration (which is otherwise closed) and the
        // post-login redirect can both pick it up.
        $this->gate->remember($token);

        $user = $request->user();

        return Inertia::render('auth/invitation', [
            'token' => $token,
            'workspace' => [
                'name' => $invitation->workspace->name,
                'initials' => $invitation->workspace->initials(),
                'logo_url' => $invitation->workspace->logoUrl(),
                'description' => $invitation->workspace->description,
            ],
            'invitedBy' => $invitation->inviter->name,
            // Null for a shareable link, which names nobody — the landing page
            // drops the "invited as …" line rather than printing an empty one.
            'email' => $invitation->email,
            'isLink' => $invitation->isLink(),
            'role' => $invitation->role->label(),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'isAuthenticated' => $user !== null,
            // Signed in as the wrong person: the UI offers to switch accounts.
            'emailMatches' => $user !== null && $invitation->isAddressedTo($user),
        ]);
    }

    public function store(
        string $token,
        Request $request,
        AcceptWorkspaceInvitation $acceptInvitation,
    ): RedirectResponse {
        $invitation = WorkspaceInvitation::findByToken($token);

        if ($invitation === null) {
            abort(404);
        }

        $this->authorize('accept', $invitation);

        $membership = $acceptInvitation->handle($invitation, $request->user());

        $this->gate->forget();

        return to_route('workspaces.show', ['workspace' => $membership->workspace->slug])
            ->with('success', "Welcome to {$membership->workspace->name}.");
    }
}
