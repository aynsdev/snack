<?php

namespace App\Notifications;

use App\Enums\NotificationReason;
use App\Models\Workspace;
use App\Notifications\Contracts\ScopedToWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody put a task in your name.
 *
 * # Why this is not a MessageNotification
 *
 * That class is one class for three reasons because those three differ only in
 * why they fired. This differs in what it is *about*: there is no message, no
 * container, and no preview of anything said — so it would have arrived as a
 * MessageNotification with every message-shaped field empty, and every reader of
 * the payload would have had to learn that some rows have no message.
 *
 * What it does share is the shape of the row: same reason-as-`type`, same
 * plain-data payload assembled by the caller, same "the stored row and the live
 * broadcast are the same object seen twice". See {@see MessageNotification} for
 * the reasoning behind each of those, which applies here unchanged.
 *
 * # No mail
 *
 * Deliberately in-app only for now. An assignment is not time-critical the way
 * being named in a conversation you are absent from is — the board is where it
 * will be seen, and the person doing the assigning is usually in the room.
 */
class TaskAssignedNotification extends Notification implements ScopedToWorkspace, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  built by App\Actions\Projects\AnnounceAssignment
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly array $payload,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationReason::TaskAssigned->value;
    }

    /**
     * Set for the reason {@see MessageNotification::broadcastType()} spells out:
     * `BroadcastNotificationCreated` overwrites `type` with the class name after
     * `toBroadcast()` has run, so the live arrival would otherwise disagree with
     * the stored row about the one field the client switches on.
     */
    public function broadcastType(): string
    {
        return NotificationReason::TaskAssigned->value;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reason' => NotificationReason::TaskAssigned->value,
            'workspace_id' => $this->workspace->id,

            // Every row carries it so the client never has to treat its absence
            // as a third case. Assignments never coalesce: being handed two
            // tasks is two things to do.
            'count' => 1,
            ...$this->payload,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            ...$this->toArray($notifiable),
            'is_read' => false,
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actor = (string) data_get($this->payload, 'actor.name', 'Someone');
        $reference = (string) data_get($this->payload, 'task.reference', '');

        return (new MailMessage)
            ->subject("{$reference} was assigned to you")
            ->greeting("{$actor} assigned you a task.")
            ->line($reference.' — '.(string) data_get($this->payload, 'task.title', ''))
            ->action('Open the board', $this->url());
    }

    /**
     * Built here rather than stored, for the reason MessageNotification gives:
     * an absolute URL written at send time outlives a domain change inside every
     * row already in the table.
     */
    private function url(): string
    {
        $slug = data_get($this->payload, 'task.project_slug');

        if (! is_string($slug)) {
            return route('workspaces.show', ['workspace' => $this->workspace->slug]);
        }

        return route('projects.board', [
            'workspace' => $this->workspace->slug,
            'project' => $slug,
        ]);
    }
}
