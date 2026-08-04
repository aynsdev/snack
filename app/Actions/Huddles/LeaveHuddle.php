<?php

namespace App\Actions\Huddles;

use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;

/**
 * Takes somebody out of a huddle, and closes the huddle if that was the last of
 * them.
 *
 * Authorization is the caller's responsibility, but the bar is deliberately low
 * — see {@see App\Policies\HuddlePolicy::leave()}. Getting *out* of a call must
 * work even for somebody whose right to be in it has just been revoked.
 *
 * # Idempotent, because the client will call it more than once
 *
 * Hanging up is announced by a button, by the page unloading, and by the tab
 * being closed, and those can all fire for one departure. A second call finds
 * no live row, changes nothing, and — importantly — broadcasts nothing: a
 * redundant "the roster changed" frame is how a huddle bar ends up flickering
 * for everybody else.
 */
class LeaveHuddle
{
    public function __construct(
        private readonly AnnounceHuddle $announce,
        private readonly EndHuddle $end,
    ) {}

    /**
     * @return bool Whether this call is what removed them.
     */
    public function handle(Huddle $huddle, User $user): bool
    {
        $left = HuddleParticipant::query()
            ->where('huddle_id', $huddle->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        if ($left === 0) {
            return false;
        }

        $huddle->unsetRelation('participants')->unsetRelation('activeParticipants');

        /*
         * An empty huddle is over. Ending it immediately rather than leaving it
         * to the sweep is what makes the huddle bar disappear the moment the
         * last person hangs up — a channel advertising a call with nobody in it
         * is worse than one advertising none at all, because people click it.
         *
         * The sweep still exists for the departures that are never announced:
         * see App\Console\Commands\SweepHuddles.
         */
        if ($huddle->activeParticipants()->doesntExist()) {
            // EndHuddle announces the lifecycle change itself, which covers the
            // roster too — so this must not also announce, or every huddle would
            // end with two broadcasts saying different things about it.
            $this->end->handle($huddle);

            return true;
        }

        $this->announce->roster($huddle);

        return true;
    }

    /**
     * Take somebody out of whatever huddle they are currently in, anywhere in
     * the installation.
     *
     * Called before admitting them to a new one. A person can only be in one
     * huddle at a time — LiveKit enforces the same thing at the media layer by
     * disconnecting the older connection under a duplicate identity, so without
     * this the roster would keep claiming they were in the huddle they had
     * already been kicked out of.
     *
     * @param  Huddle|null  $except  The huddle they are joining, which must not
     *                               be torn down on the way in.
     */
    public function fromAll(User $user, ?Huddle $except = null): void
    {
        $others = Huddle::query()
            ->live()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->whereHas(
                'activeParticipants',
                fn ($participants) => $participants->where('user_id', $user->id),
            )
            ->get();

        foreach ($others as $other) {
            $this->handle($other, $user);
        }
    }
}
