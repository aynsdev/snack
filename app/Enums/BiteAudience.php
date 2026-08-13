<?php

namespace App\Enums;

/**
 * Who in a workspace may summon Bite.
 *
 * A narrowing of who can *address* it, not of what it may say. What any given
 * answer is allowed to contain is decided per person by
 * {@see App\Ai\BiteContext} and cannot be widened from the admin screen — an
 * admin turning this to Everyone does not give a guest a way to read a channel
 * they are not in.
 */
enum BiteAudience: string
{
    /** Anybody who can post, guests included. */
    case Everyone = 'everyone';

    /**
     * Members and admins, but not guests.
     *
     * The usual choice for a workspace with contractors in it: a guest's answers
     * are already confined to the channels they were invited to, but an
     * assistant that will summarize those channels is still a capability worth
     * deciding on deliberately rather than inheriting.
     */
    case Members = 'members';

    /** Administrators only — for trying it out before opening it up. */
    case Admins = 'admins';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone',
            self::Members => 'Members and admins',
            self::Admins => 'Admins only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Everyone => 'Anyone who can post in a channel can summon Bite, including guests.',
            self::Members => 'Guests cannot summon Bite. Everyone else can.',
            self::Admins => 'Only workspace admins can summon Bite.',
        };
    }

    /**
     * Whether somebody holding this role may summon Bite.
     *
     * A null role is somebody with no standing in the workspace at all, which
     * this answers no for regardless of the setting: they should not have been
     * able to post the message in the first place, and "should not have" is not
     * a thing to rely on here.
     */
    public function admits(?WorkspaceRole $role): bool
    {
        if ($role === null) {
            return false;
        }

        return match ($this) {
            self::Everyone => true,
            self::Members => ! $role->isGuest(),
            self::Admins => $role->canAdministerWorkspace(),
        };
    }
}
