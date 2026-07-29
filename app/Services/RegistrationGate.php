<?php

namespace App\Services;

use App\Models\WorkspaceInvitation;

/**
 * Decides who is allowed to create an account.
 *
 * Snack is invitation-first: for an internal deployment `allow_public_
 * registration` is false, and the only way to register is to arrive holding a
 * valid invitation link. The token is stashed in the session when the
 * invitation page is opened, so it survives the round trip through
 * registration and email verification.
 */
class RegistrationGate
{
    public const SESSION_KEY = 'snack.invitation_token';

    /**
     * Remember the invitation the visitor arrived with.
     */
    public function remember(string $plainToken): void
    {
        session()->put(self::SESSION_KEY, $plainToken);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The still-redeemable invitation held in the session, if any.
     */
    public function pendingInvitation(): ?WorkspaceInvitation
    {
        $token = session()->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = WorkspaceInvitation::findByToken($token);

        return $invitation?->isPending() === true ? $invitation : null;
    }

    /**
     * Whether the current visitor may register at all.
     */
    public function isOpen(): bool
    {
        return (bool) config('snack.allow_public_registration')
            || $this->pendingInvitation() !== null;
    }

    /**
     * The address an invited visitor must register with. Registration is bound
     * to the invited email so a forwarded link cannot onboard a stranger.
     */
    public function requiredEmail(): ?string
    {
        return $this->pendingInvitation()?->email;
    }
}
