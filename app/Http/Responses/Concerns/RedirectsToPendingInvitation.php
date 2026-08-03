<?php

namespace App\Http\Responses\Concerns;

use App\Services\RegistrationGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends someone who authenticated mid-invitation back to the invitation.
 *
 * Signing in or registering is a detour on the way to joining a workspace, not
 * a destination: Fortify's own responses would drop the visitor on the
 * post-login home, which for a brand new account is the "you have no
 * workspaces" picker — a dead end when the invitation that brought them here is
 * still sitting unredeemed in their session.
 *
 * Using classes must declare a `$gate` property holding the
 * {@see RegistrationGate}.
 */
trait RedirectsToPendingInvitation
{
    /**
     * The redirect back to the invitation, or null to fall through to
     * Fortify's default response.
     */
    protected function pendingInvitationRedirect(Request $request): ?RedirectResponse
    {
        // API clients drive their own navigation; only browser redirects are
        // ours to reroute.
        if ($request->wantsJson()) {
            return null;
        }

        $token = $this->gate->pendingToken();

        return $token === null
            ? null
            : redirect()->route('invitations.show', ['token' => $token]);
    }
}
