<?php

namespace App\Actions\Presence;

use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\User;

/**
 * Records that somebody is still there, and what they are doing.
 *
 * # Why this exists at all
 *
 * `users.presence_status` and `users.last_seen_at` are read all over the
 * application — the avatar dot, `@here`, and
 * {@see App\Services\NotificationDelivery}'s decision to send an email — and for
 * a long time nothing wrote either of them. Every account therefore sat at the
 * column default of Offline forever, which is not merely a cosmetic wrong dot:
 * an Offline user is by definition away, so *every* notification also went out
 * as email, and `@here` expanded to nobody. This is the writer.
 *
 * # Do-not-disturb is the user's, not the client's
 *
 * A heartbeat may move somebody between Online and Away, because those describe
 * where their attention is and the client is the only thing that knows. It may
 * never move them out of DoNotDisturb: that is a deliberate choice, and a timer
 * quietly undoing it would make the setting useless. Their `last_seen_at` still
 * advances, so they are correctly seen as present — just not disturbable.
 */
class RecordPresence
{
    /**
     * Renew a live client's claim on being here.
     *
     * @param  PresenceStatus  $status  What the client reports: Online while its
     *                                  tab is visible, Away once it is hidden.
     */
    public function heartbeat(User $user, PresenceStatus $status): void
    {
        $this->apply($user, $user->presence_status->suppressesNotifications()
            ? $user->presence_status
            : $status);
    }

    /**
     * Let a claim decay, without accepting a new one.
     *
     * {@see App\Console\Commands\SweepPresence} demotes people whose heartbeats
     * stopped, and it must leave `last_seen_at` exactly where it was. Refreshing
     * it here would restart the very clock the demotion was measured against:
     * the first step would move somebody to Away, stamp them as just-seen, and
     * then the Offline step would never find them stale again — they would sit
     * at Away forever, which is not visibly broken and so never gets reported.
     */
    public function expire(User $user, PresenceStatus $status): void
    {
        $this->apply($user, $status, touchLastSeen: false);
    }

    /**
     * Mark somebody gone.
     *
     * Used on sign-out and by the sweep once the silence is long enough. Unlike
     * a heartbeat this *does* override do-not-disturb — "offline" is not a
     * competing claim about availability, it is the absence of one, and leaving
     * a closed browser showing a red dot forever is the exact staleness this
     * whole mechanism exists to prevent.
     */
    public function offline(User $user): void
    {
        $this->expire($user, PresenceStatus::Offline);
    }

    /**
     * Write the status, and announce it only if it actually moved.
     *
     * `last_seen_at` is written on every heartbeat regardless of whether the
     * status moved — it is the evidence the sweep runs on, and skipping it for
     * an unchanged status would mean a perfectly healthy tab decayed to Offline
     * inside five minutes.
     *
     * Written through the query builder rather than `save()` so `updated_at`
     * stays still. A beat a minute per open tab would otherwise rewrite it
     * forever, and anything that reads it as "when did this profile last
     * change" would be answering a different question than it thinks.
     */
    private function apply(User $user, PresenceStatus $status, bool $touchLastSeen = true): void
    {
        $changed = $user->presence_status !== $status;

        $attributes = ['presence_status' => $status->value];

        if ($touchLastSeen) {
            $attributes['last_seen_at'] = now();
        }

        User::query()->whereKey($user->id)->update($attributes);

        // Keep the in-memory copy honest: the caller usually goes on to
        // serialize this user into the very response the heartbeat returns.
        $user->forceFill($attributes)->syncOriginal();

        if (! $changed) {
            return;
        }

        $workspaceIds = $user->workspaces()
            ->pluck('workspaces.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Somebody who belongs to no workspace has nobody to tell.
        if ($workspaceIds === []) {
            return;
        }

        PresenceChanged::dispatch($user->id, $status, $workspaceIds);
    }
}
