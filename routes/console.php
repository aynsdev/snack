<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Maintenance
|--------------------------------------------------------------------------
|
| Uploads that were never sent are swept nightly. Without this, every
| abandoned draft and closed tab leaves bytes on the disk that nothing in the
| application will ever look at again.
|
| `withoutOverlapping` matters more than the hour does: the sweep deletes files
| one at a time, and on a large backlog a second run starting before the first
| finished would have both processes racing for the same rows.
|
*/

Schedule::command('snack:prune-uploads')
    ->dailyAt('03:15')
    ->withoutOverlapping();

/*
 * A safety net rather than the indexing path — messages are indexed by a queued
 * job as they are written. This catches rows whose job was lost to a worker
 * restart, which would otherwise be invisible: an unindexed message does not
 * error, it just never appears in a search result.
 */
Schedule::command('snack:reindex-messages')
    ->hourly()
    ->withoutOverlapping();

/*
 * The daily digest, woken hourly.
 *
 * "Send at 08:00" means 08:00 where the member is, and a workspace has people in
 * more than one timezone — so the command runs every hour and sends to whoever
 * has just reached their own configured hour. See SendNotificationDigests.
 *
 * `withoutOverlapping` because a run that is still walking memberships when the
 * next hour starts would have both processes reading the same unread rows and
 * sending the same person two copies.
 */
Schedule::command('snack:send-digests')
    ->hourly()
    ->withoutOverlapping();

/*
 * Expires presence claims whose heartbeats stopped.
 *
 * Every minute, because this is the one piece of state where being stale is the
 * whole failure: a green dot next to somebody who closed their laptop an hour
 * ago is worse than no dot at all, since people act on it. The work is two
 * indexed lookups over a set that is normally empty.
 *
 * `withoutOverlapping` for the usual reason — a slow run must not have a second
 * process demoting the same people underneath it — with a short expiry so a
 * killed run cannot wedge presence until someone notices.
 */
Schedule::command('snack:sweep-presence')
    ->everyMinute()
    ->withoutOverlapping(5);

/*
 * Closes huddles whose last participant never said goodbye.
 *
 * The same shape of problem as presence, and swept at the same cadence for the
 * same reason: a call nobody is in stays advertised in its channel header until
 * something notices, and people click it. Leaving is announced by the client
 * when it can, so this only ever finds the crashed tabs and the closed laptops.
 */
Schedule::command('snack:sweep-huddles')
    ->everyMinute()
    ->withoutOverlapping(5);
