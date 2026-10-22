<?php

namespace App\Services;

use App\Enums\NotificationReason;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MessageNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;

/**
 * Folds a repeat notification into the one already sitting unread in the inbox.
 *
 * # The problem this exists for
 *
 * A direct message raises a notification per *message*, so a conversation is
 * worth as many inbox rows as it has lines in it. Someone writing four short
 * sentences instead of one paragraph produces four rows, four broadcasts, and —
 * if the reader is away — four emails, all describing the same interruption.
 * Nothing in them differs except the preview, and only the last preview is worth
 * reading.
 *
 * So the second message in a conversation does not write a row. It rewrites the
 * first one: newest preview, newest message to jump to, a count, and a fresh
 * `created_at` so it floats back to the top. One conversation is one row until
 * the reader has seen it.
 *
 * # Why it is bounded by time as well as by read state
 *
 * "Fold while unread" alone has a failure mode worse than the problem: somebody
 * who never opens their notification centre would have one permanently unread
 * row absorbing every direct message they are ever sent, and the away-email that
 * {@see NotificationDelivery} exists to send would stop arriving after the first
 * one. The window makes the worst case one row and one email per conversation
 * per hour rather than silence.
 *
 * # Why it does not touch mentions
 *
 * {@see NotificationReason::coalescesWhileUnread()} decides, and only direct
 * messages qualify. Being named three times is three things to go and look at.
 */
class NotificationCoalescer
{
    /**
     * Fold this message into whichever recipients already have an open row for
     * the same conversation, and report which of them were handled here.
     *
     * The caller sends an ordinary notification to everyone left over — see
     * {@see NotificationDispatcher}. Returning ids rather than mutating the list
     * keeps this class free of any opinion about who *should* be notified, which
     * is {@see NotificationRecipients}' question and is settled long before
     * anything reaches here.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $payload
     * @return array<int, int> Absorbed user ids; the rest still need sending.
     */
    public function absorb(
        NotificationReason $reason,
        Workspace $workspace,
        array $userIds,
        array $payload,
    ): array {
        $containerId = data_get($payload, 'container.id');

        if (! $reason->coalescesWhileUnread() || $userIds === [] || $containerId === null) {
            return [];
        }

        $absorbed = [];

        foreach ($this->openRows($reason, $workspace, $userIds, $containerId) as $row) {
            $this->rewrite($row, $workspace, $payload);

            $absorbed[] = (int) $row->notifiable_id;
        }

        return $absorbed;
    }

    /**
     * The unread, still-recent rows these people hold for this container.
     *
     * One query for the whole audience rather than one per recipient: a group DM
     * fans out to everybody in it, and this runs on every message.
     *
     * The leading columns match `notifications_unread_index` exactly, so the
     * JSON comparison filters an index scan already narrowed to one person's
     * unread rows in one workspace rather than scanning the table.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Notification>
     */
    private function openRows(
        NotificationReason $reason,
        Workspace $workspace,
        array $userIds,
        mixed $containerId,
    ): Collection {
        $rows = Notification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->where('workspace_id', $workspace->id)
            ->whereNull('read_at')
            ->where('type', $reason->value)
            ->where('created_at', '>=', now()->subMinutes($this->windowMinutes()))
            ->where('data->container->id', $containerId)
            /*
             * Newest first, then one row per person.
             *
             * There can legitimately be more than one open row for a
             * conversation — the window expiring starts a fresh one while the
             * previous is still unread — and rewriting both would show the same
             * message twice under two different counts.
             */
            ->orderByDesc('created_at')
            ->get()
            ->unique('notifiable_id')
            ->values();

        return new Collection($rows->all());
    }

    /**
     * Point an existing row at the newer message.
     *
     * `created_at` is bumped rather than left alone: the inbox is ordered by it,
     * and a row that quietly changed its contents while staying three screens
     * down is a notification nobody receives.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rewrite(Notification $row, Workspace $workspace, array $payload): void
    {
        $data = [
            'reason' => $row->type,
            'workspace_id' => $workspace->id,
            ...$payload,
            'count' => (int) data_get($row->data, 'count', 1) + 1,
        ];

        /*
         * Written through the query builder rather than by saving the model: the
         * jsonb column is replaced wholesale and `created_at` is deliberately
         * being moved, which Eloquent's own timestamp handling would undo.
         */
        DB::table('notifications')
            ->where('id', $row->id)
            ->update([
                'data' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->announce($row, $workspace, $data);
    }

    /**
     * Tell an open tab that the row it is already rendering has changed.
     *
     * Deliberately the *same* event a new notification arrives on, carrying the
     * existing row's id. The client keys its list by id, so a payload whose id it
     * already holds replaces that entry in place and hoists it to the top —
     * exactly what just happened in the database — while a genuinely new one is
     * prepended. One event, one code path, and no way for the two to disagree
     * about ordering.
     *
     * The badge is untouched by design: an absorbed message adds no unread row,
     * so the count the client already has is still right.
     *
     * @param  array<string, mixed>  $data
     */
    private function announce(Notification $row, Workspace $workspace, array $data): void
    {
        $user = User::query()->find($row->notifiable_id);
        $reason = $row->reason();

        if ($user === null || $reason === null) {
            return;
        }

        $notification = new MessageNotification($reason, $workspace, $data);

        // What BroadcastNotificationCreated reads to fill in the payload's `id`.
        // Setting it is what makes this an update rather than a second arrival.
        $notification->id = $row->id;

        $user->notify(MessageNotification::pushFor($reason, $workspace, $data, $row->id));

        event(new BroadcastNotificationCreated($user, $notification, [
            ...$data,
            'is_read' => false,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    private function windowMinutes(): int
    {
        return (int) config('snack.notifications.coalesce_minutes', 60);
    }
}
