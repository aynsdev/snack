<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Messages\PushMessage;
use App\Services\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Somebody started a huddle in a conversation you are in: the phone rings.
 *
 * A separate class from {@see MessageNotification} for the reason
 * {@see TaskAssignedNotification} is one — there is no message, no preview,
 * and nothing that was said — and for a stronger one besides: this is the only
 * notification here that is *about right now*. A mention is still worth reading
 * an hour later; a call that ended an hour ago is not worth anything at all.
 *
 * # Push only
 *
 * No `database`, no `broadcast`, no `mail`, deliberately.
 *
 * - **No inbox row.** The notification centre is a record of things addressed
 *   to you that are still worth going back to, and every row it renders is a
 *   message or a task. A huddle that has since ended would sit there pointing at
 *   nothing, and giving it a row would mean a new {@see App\Enums\NotificationReason}
 *   case that both the web inbox and the mobile Activity tab would have to
 *   learn to render. Whether the call happened is already recorded where it
 *   belongs: the huddle history.
 * - **No broadcast.** The person who has Snack open already hears
 *   {@see App\Events\HuddleActivity} on their own channel, now carrying who
 *   started it — that is what the in-app ringing UI listens to. A second live
 *   arrival for the same moment would ring twice.
 * - **No mail.** An email that arrives after the call is over is the purest
 *   form of noise.
 *
 * # The same delivery rules as a message
 *
 * Who is *eligible* is decided upstream, by
 * {@see App\Actions\Huddles\AnnounceHuddle}, from the same mute and
 * notification-level rules a direct message obeys. Whether a phone actually
 * buzzes is {@see NotificationDelivery::shouldPush()}, unchanged: push turned
 * off, do-not-disturb, quiet hours, and "is already looking at Snack" all win.
 * The last one matters more here than anywhere — somebody with the app open is
 * rung by the realtime event, and a lock-screen banner on top of that would be
 * the same call announced twice.
 *
 * # The payload is built by the caller
 *
 * Plain data, for the reason {@see MessageNotification} gives: it serializes
 * onto the queue without models, so a huddle that ends — or a starter who is
 * renamed — between dispatch and delivery cannot turn the push into a failed
 * job.
 */
class HuddleStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The wire value of `reason` in the push `data`. Not a
     * {@see App\Enums\NotificationReason} case, because that enum is the inbox
     * row's `type` and this writes no row — see the class docblock.
     */
    public const REASON = 'huddle_started';

    /**
     * @param  array{huddle_id: string, conversation_id: string, starter: array{id: int, name: string}}  $payload
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly array $payload,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        return app(NotificationDelivery::class)->shouldPush($notifiable, $notifiable->membershipIn($this->workspace))
            ? ['push']
            : [];
    }

    /**
     * Who is calling as the title, the way a phone shows a call, and enough
     * data for the app to open the conversation with the huddle in it.
     *
     * Same sound and Android channel as a message push — {@see PushMessage}
     * fixes both — so ringing needs no new channel on the device. Grouped with
     * the conversation's own pushes on the lock screen, since that is where
     * tapping it leads.
     */
    public function toPush(object $notifiable): PushMessage
    {
        $conversationId = $this->payload['conversation_id'];

        return new PushMessage(
            title: $this->payload['starter']['name'],
            body: 'Started a huddle — tap to join',
            data: [
                'reason' => self::REASON,
                'workspace' => $this->workspace->slug,
                'container_kind' => 'conversation',
                'container' => $conversationId,
                'huddle_id' => $this->payload['huddle_id'],
            ],
            thread: 'conversation:'.$conversationId,
        );
    }
}
