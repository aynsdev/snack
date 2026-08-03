<?php

namespace App\Console\Commands;

use App\Actions\Presence\RecordPresence;
use App\Enums\PresenceStatus;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Expires presence claims whose heartbeats stopped.
 *
 * Browsers do not say goodbye. A closed laptop, a killed tab, a train going into
 * a tunnel — all of them look identical from here, and all of them leave a green
 * dot burning next to somebody who left an hour ago. Presence is therefore a
 * claim with an expiry, and this is what expires it.
 *
 * Two steps rather than one, because "idle" and "gone" are different answers and
 * collapsing them loses the useful one:
 *
 *  1. Online but quiet past `away_after_seconds` → Away. Still here, not looking.
 *  2. Anything still connected past `offline_after_seconds` → Offline.
 *
 * Do-not-disturb is passed over in step one — it is a deliberate choice, not a
 * guess about attention — but not in step two: a closed browser is gone whatever
 * it last chose to display.
 *
 * Runs every minute. The work is two indexed updates over a set that is normally
 * empty, so the cost of running it often is far below the cost of a stale dot.
 */
#[Signature('snack:sweep-presence')]
#[Description('Mark idle users away and disconnected users offline.')]
class SweepPresence extends Command
{
    public function handle(RecordPresence $presence): int
    {
        $awayAfter = (int) config('snack.presence.away_after_seconds', 150);
        $offlineAfter = (int) config('snack.presence.offline_after_seconds', 300);

        $wentAway = $this->transition(
            $presence,
            [PresenceStatus::Online],
            $awayAfter,
            PresenceStatus::Away,
        );

        /*
         * Includes Away — the two windows are consecutive stages of the same
         * silence, so somebody demoted a step ago must still be able to fall the
         * rest of the way — and DoNotDisturb, per the note above.
         */
        $wentOffline = $this->transition(
            $presence,
            [PresenceStatus::Online, PresenceStatus::Away, PresenceStatus::DoNotDisturb],
            $offlineAfter,
            PresenceStatus::Offline,
        );

        $this->info("Presence swept: {$wentAway} away, {$wentOffline} offline.");

        return self::SUCCESS;
    }

    /**
     * Move everyone stale enough, through the action rather than in bulk SQL.
     *
     * A single `UPDATE ... WHERE` would be one query instead of N, but it would
     * also broadcast nothing, and a status nobody is told about is the bug this
     * command was written to fix. The set is people who went quiet in the last
     * minute, which is small; correctness is worth the queries.
     *
     * @param  array<int, PresenceStatus>  $from
     */
    private function transition(
        RecordPresence $presence,
        array $from,
        int $seconds,
        PresenceStatus $to,
    ): int {
        $cutoff = now()->subSeconds(max(30, $seconds));
        $swept = 0;

        User::query()
            ->whereIn('presence_status', array_column($from, 'value'))
            // A null `last_seen_at` is somebody who has never had a heartbeat
            // at all, so there is nothing to expire and no claim to withdraw.
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->chunkById(200, function ($users) use ($presence, $to, &$swept): void {
                foreach ($users as $user) {
                    // `expire`, never `heartbeat`: a demotion is not evidence of
                    // life, and stamping `last_seen_at` here would reset the
                    // clock the next stage measures against.
                    $presence->expire($user, $to);

                    $swept++;
                }
            });

        return $swept;
    }
}
