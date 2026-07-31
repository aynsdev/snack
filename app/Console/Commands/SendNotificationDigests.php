<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\WorkspaceMember;
use App\Notifications\DailyDigestNotification;
use App\Services\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Emails each opted-in member a summary of what they missed.
 *
 * # Why this runs hourly
 *
 * "Send the digest at 08:00" means 08:00 where the member is, and a workspace
 * has people in more than one place. So the schedule wakes this up every hour
 * and it sends to whoever has just reached the configured hour in their own
 * timezone — one cheap query an hour rather than one cron entry per zone.
 *
 * # What goes in it
 *
 * Unread notifications only, from the last window. Something already read has
 * been dealt with, and summarising it would make the digest a second copy of the
 * inbox rather than a catch-up. Nothing is stamped read by sending: the digest
 * tells you what is waiting, it does not clear it for you.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'snack:send-digests
                            {--force : Send to every opted-in member regardless of their local time}';

    protected $description = 'Email opted-in members a summary of their unread notifications';

    public function handle(NotificationDelivery $delivery): int
    {
        $sent = 0;

        /*
         * Chunked by id rather than loaded whole: this walks every membership in
         * the installation, and a workspace estate large enough to need a digest
         * is one large enough that the full list should not be in memory.
         */
        WorkspaceMember::query()
            ->active()
            ->with(['user', 'workspace'])
            ->chunkById(200, function (Collection $memberships) use ($delivery, &$sent): void {
                foreach ($memberships as $membership) {
                    if ($this->send($membership, $delivery)) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} digest".($sent === 1 ? '.' : 's.'));

        return self::SUCCESS;
    }

    private function send(WorkspaceMember $membership, NotificationDelivery $delivery): bool
    {
        if (! $membership->wantsDailyDigest()) {
            return false;
        }

        $user = $membership->user;

        if (! $this->isDigestHourFor($user->timezone)) {
            return false;
        }

        /*
         * Someone who has been reading all morning does not need to be told what
         * they read. The same idle rule that decides whether a single mention is
         * worth an email decides whether a day's worth is.
         */
        if (! $delivery->isAway($user)) {
            return false;
        }

        $unread = Notification::query()
            ->forWorkspace($membership->workspace, $user)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subHours($this->windowHours()))
            ->latest()
            ->get();

        if ($unread->isEmpty()) {
            return false;
        }

        $user->notify(new DailyDigestNotification(
            workspace: $membership->workspace,
            items: $this->items($unread),
            total: $unread->count(),
        ));

        return true;
    }

    /**
     * The rows the email actually lists, flattened to strings.
     *
     * Capped, because an email with two hundred lines in it is not read — the
     * total is reported separately so the remainder is still accounted for.
     *
     * @param  Collection<int, Notification>  $unread
     * @return array<int, array{reason: string, actor: string, where: string|null, preview: string}>
     */
    private function items(Collection $unread): array
    {
        return $unread
            ->take((int) config('snack.notifications.digest.max_items', 15))
            ->map(fn (Notification $notification): array => [
                'reason' => $notification->type,
                'actor' => (string) data_get($notification->data, 'actor.name', 'Someone'),
                'where' => $this->where($notification),
                'preview' => (string) data_get($notification->data, 'preview', ''),
            ])
            ->values()
            ->all();
    }

    /**
     * "#design", or nothing for a conversation — a DM has no name that reads the
     * same for every participant, so naming one means picking somebody else's
     * answer.
     */
    private function where(Notification $notification): ?string
    {
        $container = data_get($notification->data, 'container');

        if (! is_array($container) || $container['kind'] !== 'channel') {
            return null;
        }

        return '#'.$container['slug'];
    }

    private function isDigestHourFor(string $timezone): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return (int) Carbon::now($timezone)->format('G')
            === (int) config('snack.notifications.digest.hour', 8);
    }

    private function windowHours(): int
    {
        return (int) config('snack.notifications.digest.window_hours', 24);
    }
}
