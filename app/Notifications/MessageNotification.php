<?php

namespace App\Notifications;

use App\Enums\NotificationReason;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Contracts\ScopedToWorkspace;
use App\Services\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something addressed to you happened in a message.
 *
 * One class for all three reasons rather than three near-identical ones: a
 * mention, a DM, and a thread reply differ in *why* they were raised and in
 * nothing else — same payload, same delivery rules, same inbox row — and
 * splitting them would put the same fifty lines in three files and let them
 * drift. The reason is carried as data and surfaces as the row's `type`, so the
 * inbox can still be filtered by it.
 *
 * # The payload is built by the caller
 *
 * `$payload` arrives already assembled by
 * {@see App\Actions\Notifications\DispatchMessageNotifications}, which has the
 * message, sender, and container in memory anyway. That keeps this class free
 * of models: it serializes onto the queue as plain data, so a worker never
 * lazy-loads a relation, and a message edited or deleted between dispatch and
 * delivery cannot turn a notification into a failed job.
 *
 * The preview is therefore a snapshot of what was said at the time, which is
 * what a notification should be. The inbox separately hides rows whose message
 * has since been deleted — see {@see App\Http\Controllers\Notifications\NotificationController}.
 */
class MessageNotification extends Notification implements ScopedToWorkspace, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly NotificationReason $reason,
        private readonly Workspace $workspace,
        private readonly array $payload,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * The row's `type` column: the reason, not this class's name.
     *
     * A namespace in the database is a refactor away from being wrong, and
     * nothing querying the inbox cares which PHP class wrote the row.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->reason->value;
    }

    /**
     * The same answer for the broadcast payload, and not optional.
     *
     * `BroadcastNotificationCreated` overwrites `type` with this — defaulting to
     * the class name — *after* `toBroadcast()` has run, so setting it there is
     * not enough. Without this the stored row and the live arrival disagree
     * about the one field the client switches on, and the client is handed a PHP
     * namespace where it expects a reason.
     */
    public function broadcastType(): string
    {
        return $this->reason->value;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /*
         * `database` and `broadcast` are the same event seen twice: the row is
         * the record, the broadcast is the live arrival. Both always go — a
         * notification centre that only fills in while you are watching is not
         * a centre.
         */
        $via = ['database', 'broadcast'];

        if ($notifiable instanceof User && $this->shouldMail($notifiable)) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reason' => $this->reason->value,
            'workspace_id' => $this->workspace->id,

            /*
             * How many messages this one row now stands for. Always 1 here —
             * {@see App\Services\NotificationCoalescer} is what raises it, by
             * rewriting a row rather than by sending another notification — but
             * stated explicitly so every row carries the field and the client
             * never has to treat its absence as a third case.
             *
             * Spread last so a coalesced payload's own count wins.
             */
            'count' => 1,
            ...$this->payload,
        ];
    }

    /**
     * The live arrival.
     *
     * Carries the same shape as the stored row plus the row's own id, so a
     * client can render the toast and mark it read without a follow-up request.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            ...$this->toArray($notifiable),

            // Mirrors NotificationResource exactly, so the client can prepend an
            // arrival to the list it already rendered without a second shape to
            // convert between. `type` is set by broadcastType(), not here.
            'is_read' => false,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actor = (string) data_get($this->payload, 'actor.name', 'Someone');
        $where = $this->containerLabel();

        return (new MailMessage)
            ->subject($this->reason->headline($actor).' in '.$this->workspace->name)
            ->greeting($this->reason->headline($actor).'.')
            ->line($where === null ? '' : "In {$where}:")
            ->line('"'.$this->preview().'"')
            ->action('Open '.$this->workspace->name, $this->url())
            ->line('You are getting this because you were away when it arrived. You can turn these off in your notification preferences.');
    }

    private function shouldMail(User $notifiable): bool
    {
        return app(NotificationDelivery::class)->shouldMail(
            $notifiable,
            $notifiable->membershipIn($this->workspace),
        );
    }

    /**
     * "#design", or "your conversation" when the container is a DM — a
     * conversation has no name that reads the same for every participant, so
     * naming one in an email would mean picking somebody else's answer.
     */
    private function containerLabel(): ?string
    {
        $container = data_get($this->payload, 'container');

        if (! is_array($container)) {
            return null;
        }

        return $container['kind'] === 'channel'
            ? '#'.$container['slug']
            : 'your conversation';
    }

    private function preview(): string
    {
        $preview = (string) data_get($this->payload, 'preview', '');

        return $preview === '' ? '(no text)' : $preview;
    }

    /**
     * Built here rather than stored in the payload: an absolute URL bakes in the
     * app's domain, and one written at send time would outlive a domain change
     * inside every row already in the table.
     */
    private function url(): string
    {
        $container = data_get($this->payload, 'container');

        if (is_array($container) && $container['kind'] === 'channel') {
            return route('channels.show', [
                'workspace' => $this->workspace->slug,
                'channel' => $container['slug'],
            ]);
        }

        if (is_array($container)) {
            return route('conversations.show', [
                'workspace' => $this->workspace->slug,
                'conversation' => $container['id'],
            ]);
        }

        return route('workspaces.show', ['workspace' => $this->workspace->slug]);
    }
}
