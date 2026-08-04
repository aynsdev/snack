<?php

namespace App\Services;

use App\Enums\NotificationReason;
use App\Http\Resources\ContainerSummary;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MessageNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Turns one sent message into the notifications it should raise.
 *
 * Runs on a queue rather than on the send path — see
 * {@see App\Jobs\DeliverMessageNotifications} — because the fan-out reads every
 * membership in the container, and pressing Enter should not wait on it.
 *
 * The payload is assembled once here, with the message and its relations already
 * in memory, and handed to every recipient's notification as plain data. Nothing
 * downstream re-loads the message, so a hundred recipients cost one set of
 * queries rather than a hundred.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
        private readonly NotificationCoalescer $coalescer,
    ) {}

    public function dispatch(int $messageId): void
    {
        $message = Message::query()
            ->with(['sender', 'channel', 'conversation', 'workspace'])
            ->find($messageId);

        // Deleted between the send and this job running. Nothing to announce.
        if ($message === null || $message->sender === null) {
            return;
        }

        $recipients = $this->recipients->for($message);

        if ($recipients === []) {
            return;
        }

        $payload = $this->payload($message);
        $workspace = $message->workspace;

        // Grouped by reason so each distinct notification is built and sent
        // once, to everyone who shares it.
        foreach ($this->groupByReason($recipients) as $reason => $userIds) {
            $this->deliver(NotificationReason::from($reason), $workspace, $userIds, $payload);
        }
    }

    /**
     * Everyone who shares one reason, in one send.
     *
     * Constructed once per reason rather than once per person. Laravel still
     * queues delivery per notifiable, which is what keeps one bounced email from
     * holding up the other ninety-nine.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    private function deliver(
        NotificationReason $reason,
        Workspace $workspace,
        array $userIds,
        array $payload,
    ): void {
        /*
         * Anyone still holding an open row for this container has it rewritten
         * in place instead of being sent a second one — see
         * {@see NotificationCoalescer} for why a chatty DM should not be worth an
         * inbox row per line. They drop out here, so no notification is
         * constructed for them at all and no second email goes out.
         */
        $absorbed = $this->coalescer->absorb($reason, $workspace, $userIds, $payload);

        $remaining = array_values(array_diff($userIds, $absorbed));

        if ($remaining === []) {
            return;
        }

        $users = User::query()->whereIn('id', $remaining)->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send(
            $users,
            new MessageNotification($reason, $workspace, $payload),
        );
    }

    /**
     * What every recipient of this message sees.
     *
     * Self-contained on purpose: the same array backs the stored row, the live
     * broadcast, and the email, so a client can render an arriving notification
     * without a follow-up request and a worker can build an email without the
     * message still existing.
     *
     * @return array<string, mixed>
     */
    private function payload(Message $message): array
    {
        $sender = $message->sender;

        return [
            'message_id' => $message->id,
            // The thread this belongs to, so the inbox can open a reply in the
            // thread panel rather than dropping the reader into the container
            // with nothing highlighted.
            'thread_root_id' => $message->parent_message_id,
            'preview' => $message->preview(),
            'actor' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'username' => $sender->username,
                'initials' => $sender->initials(),
                'avatar_url' => $sender->avatarUrl(),
            ],
            'container' => ContainerSummary::for($message),
        ];
    }

    /**
     * @param  array<int, NotificationReason>  $recipients
     * @return array<string, array<int, int>>
     */
    private function groupByReason(array $recipients): array
    {
        $grouped = [];

        foreach ($recipients as $userId => $reason) {
            $grouped[$reason->value][] = $userId;
        }

        return $grouped;
    }
}
