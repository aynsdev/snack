<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Carbon;

/**
 * Decides *how* a notification reaches somebody, once it has been decided that
 * they should get one at all.
 *
 * Who gets notified is {@see NotificationRecipients}'s question and is answered
 * from notification levels and mutes. This answers the separate one — the
 * in-app centre always gets the row, but should an email go out as well?
 *
 * # The rule
 *
 * Email is for people who are not here to see the banner. Someone reading the
 * channel does not need the same message in their inbox ten seconds later; that
 * is how an application teaches people to filter its mail.
 *
 * "Not here" is two conditions, because either one alone is wrong:
 *
 * - `presence_status` is Offline — they have no live socket.
 * - `last_seen_at` is older than the idle window — they *do* have a socket, but
 *   the tab has been sitting untouched since lunch. Presence alone would call
 *   that person present and silently drop the email.
 *
 * Do-not-disturb suppresses mail outright, per
 * {@see App\Enums\PresenceStatus::suppressesNotifications()}, and so does a
 * member's own quiet-hours schedule. Neither suppresses the database row: the
 * notification centre is a record, not an interruption, and finding an empty
 * inbox after a quiet evening would be worse than a full one.
 */
class NotificationDelivery
{
    /**
     * Whether this notification should also be emailed.
     */
    public function shouldMail(User $user, ?WorkspaceMember $membership): bool
    {
        if (! $this->wantsEmail($membership)) {
            return false;
        }

        if ($user->presence_status->suppressesNotifications()) {
            return false;
        }

        if ($membership !== null && $this->isWithinQuietHours($user, $membership)) {
            return false;
        }

        return $this->isAway($user);
    }

    /**
     * Whether the user is away from Snack right now.
     *
     * Public because the digest asks the same question for a different reason:
     * it should not summarise a day for somebody who has been reading all of it.
     */
    public function isAway(User $user): bool
    {
        if (! $user->presence_status->isConnected()) {
            return true;
        }

        $lastSeen = $user->last_seen_at;

        if ($lastSeen === null) {
            return true;
        }

        return $lastSeen->lt(now()->subMinutes($this->idleMinutes()));
    }

    /**
     * Email is opt-out rather than opt-in: a mention that nobody sees is the
     * failure mode this whole feature exists to prevent.
     */
    private function wantsEmail(?WorkspaceMember $membership): bool
    {
        if ($membership === null) {
            return false;
        }

        return (bool) data_get($membership->notification_preferences, 'email.enabled', true);
    }

    /**
     * The member's own quiet hours, evaluated in *their* timezone rather than
     * the server's — the whole point of the setting is that 22:00 means 22:00
     * where they are.
     *
     * A window that wraps midnight (22:00 to 07:00) is the normal case, so the
     * comparison is a union rather than a range when the end is before the start.
     */
    private function isWithinQuietHours(User $user, WorkspaceMember $membership): bool
    {
        $schedule = $membership->doNotDisturbSchedule();

        if ($schedule === null) {
            return false;
        }

        $now = Carbon::now($user->timezone)->format('H:i');

        [$start, $end] = [$schedule['start'], $schedule['end']];

        return $start <= $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    private function idleMinutes(): int
    {
        return (int) config('snack.notifications.idle_minutes', 10);
    }
}
