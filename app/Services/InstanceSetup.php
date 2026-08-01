<?php

namespace App\Services;

use App\Models\User;

/**
 * Whether this installation has been initialized yet.
 *
 * Snack ships invitation-first (see {@see RegistrationGate}), which leaves a
 * fresh installation with no way in: registration 404s because nobody holds an
 * invitation, and the login form has no account to authenticate against.
 * First-run setup is the one moment an account may be created without one.
 *
 * The condition is "no user exists" rather than a config flag or a lock file,
 * because that is the one signal which cannot be forged, cannot drift out of
 * sync with reality, and cannot be re-entered once it stops being true. A flag
 * left switched on after the first account exists would reopen unauthenticated
 * account creation on a live installation.
 */
class InstanceSetup
{
    /**
     * Deliberately not memoized. Setup flips this state mid-request, and a
     * cached answer would let the guard middleware and the controller disagree
     * within a single request. The query is one indexed existence check and
     * runs only on the guest entry points, never on authenticated traffic —
     * anyone already signed in is itself proof the instance is set up.
     */
    public function isPending(): bool
    {
        return ! User::query()->exists();
    }

    public function isComplete(): bool
    {
        return ! $this->isPending();
    }
}
