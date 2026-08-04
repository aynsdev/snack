<?php

namespace App\Console\Commands;

use App\Actions\Huddles\EndHuddle;
use App\Models\Huddle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes huddles that everybody has already left.
 *
 * Hanging up is announced by the client, and like every other client
 * announcement it cannot be relied on: a closed laptop, a killed tab, or a
 * connection that simply stops all look identical from here and all send
 * nothing. {@see App\Actions\Huddles\LeaveHuddle} ends a huddle the moment its
 * last participant *tells* us they left; this is what handles the ones who
 * never do.
 *
 * The failure it prevents is not cosmetic. A huddle nobody is in stays
 * advertised in its channel header forever, and people click it — landing alone
 * in a room the person who "started" it left an hour ago, which reads as the
 * feature being broken rather than as stale state.
 *
 * # Why there is a grace period at all
 *
 * A huddle is briefly empty in perfectly healthy circumstances: the only
 * participant reloads the page, or moves from laptop to phone, and for a second
 * or two the roster is genuinely zero. Closing on sight would tear down a call
 * somebody is in the middle of rejoining. `snack.huddles.empty_grace_seconds`
 * is how long an empty room is given to refill.
 *
 * Runs every minute, like the presence sweep, and for the same reason: the work
 * is one indexed lookup over a set that is almost always empty, and staleness
 * here is the entire failure mode.
 */
#[Signature('snack:sweep-huddles')]
#[Description('End live huddles that no longer have anyone in them.')]
class SweepHuddles extends Command
{
    public function handle(EndHuddle $end): int
    {
        $grace = (int) config('snack.huddles.empty_grace_seconds', 60);

        $cutoff = now()->subSeconds(max(10, $grace));

        $swept = 0;

        Huddle::query()
            ->live()
            /*
             * Only huddles old enough to have been given their grace period.
             * Without this the sweep would close a huddle in the instant
             * between its row being inserted and its first participant row
             * following it — which is a window JoinHuddle genuinely has.
             */
            ->where('started_at', '<', $cutoff)
            /*
             * Nobody currently in it, and nobody who left recently enough to
             * still be coming back. The second half is what distinguishes an
             * abandoned huddle from one whose single participant is reloading
             * the page.
             */
            ->whereDoesntHave('activeParticipants')
            ->whereDoesntHave(
                'participants',
                fn ($participants) => $participants->where('left_at', '>=', $cutoff),
            )
            ->with(['channel', 'conversation'])
            ->chunkById(100, function ($huddles) use ($end, &$swept): void {
                foreach ($huddles as $huddle) {
                    /*
                     * Through the action rather than a bulk UPDATE, for the
                     * reason the presence sweep gives: one query would be
                     * cheaper and would broadcast nothing, leaving every open
                     * client still showing a huddle that this command just
                     * decided was over.
                     */
                    if ($end->handle($huddle)) {
                        $swept++;
                    }
                }
            });

        $this->info("Huddles swept: {$swept} ended.");

        return self::SUCCESS;
    }
}
