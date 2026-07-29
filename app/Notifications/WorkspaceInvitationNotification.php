<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time invitation link.
 *
 * The plaintext token is passed in rather than read from the model, because the
 * model only ever stores its hash. Queued so that inviting a large group does
 * not block the admin's request.
 */
class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly WorkspaceInvitation $invitation,
        private readonly string $plainToken,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $inviter = $this->invitation->inviter;
        $url = route('invitations.show', ['token' => $this->plainToken]);

        return (new MailMessage)
            ->subject("{$inviter->name} invited you to {$workspace->name} on Snack")
            ->greeting('Small chats. Big work.')
            ->line("{$inviter->name} has invited you to join the **{$workspace->name}** workspace on Snack as a {$this->invitation->role->label()}.")
            ->action('Join '.$workspace->name, $url)
            ->line("This invitation expires {$this->invitation->expires_at->diffForHumans()}.")
            ->line('If you were not expecting this invitation, you can safely ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->invitation->workspace_id,
            'workspace_name' => $this->invitation->workspace->name,
            'invited_by' => $this->invitation->invited_by,
            'role' => $this->invitation->role->value,
            'expires_at' => $this->invitation->expires_at->toIso8601String(),
        ];
    }
}
