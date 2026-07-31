<?php

namespace App\Notifications;

use App\Enums\NotificationReason;
use App\Models\Workspace;
use App\Notifications\Contracts\ScopedToWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What you missed in one workspace, once a day.
 *
 * Mail only. It writes no row in the notification centre on purpose: a digest is
 * a summary of entries that are already sitting in there, and adding a
 * seventeenth notification saying "you have sixteen notifications" would be the
 * badge arguing with itself.
 *
 * Built from a plain array by {@see App\Console\Commands\SendNotificationDigests}
 * rather than from models, so the whole thing serializes onto the queue as data
 * and a worker never re-reads a message that may since have been deleted.
 */
class DailyDigestNotification extends Notification implements ScopedToWorkspace, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{reason: string, actor: string, where: string|null, preview: string}>  $items
     * @param  int  $total  Everything unread, which may exceed the items listed.
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly array $items,
        private readonly int $total,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Here is what you missed.')
            ->line("While you were away from **{$this->workspace->name}**:");

        foreach ($this->items as $item) {
            $mail->line($this->line($item));
        }

        $remaining = $this->total - count($this->items);

        if ($remaining > 0) {
            $mail->line("…and {$remaining} more.");
        }

        return $mail
            ->action('Open '.$this->workspace->name, route('notifications.index', [
                'workspace' => $this->workspace->slug,
            ]))
            ->line('You can turn this daily summary off in your notification preferences.');
    }

    private function subject(): string
    {
        $noun = $this->total === 1 ? 'notification' : 'notifications';

        return "{$this->total} unread {$noun} in {$this->workspace->name}";
    }

    /**
     * @param  array{reason: string, actor: string, where: string|null, preview: string}  $item
     */
    private function line(array $item): string
    {
        $reason = NotificationReason::tryFrom($item['reason']);

        $headline = $reason === null
            ? "{$item['actor']} wrote"
            : $reason->headline($item['actor']);

        $where = $item['where'] === null ? '' : " in {$item['where']}";

        return "**{$headline}**{$where}: {$item['preview']}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'total' => $this->total,
        ];
    }
}
