<?php

namespace App\Actions\Hr;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\SendMessage;
use App\Actions\Messages\UpdateMessage;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Throwable;

/**
 * Publishes an announcement in HR and, optionally, posts it to a channel.
 *
 * # Why the post is the admin's own message
 *
 * Notifying everyone is an `@everyone` mention, which only admins may send —
 * see {@see App\Enums\WorkspaceRole::canMentionEveryone()}. Bot accounts are
 * deliberately plain members so that no bot, webhook, or integration can ping a
 * whole workspace; posting as the admin keeps that rule intact, and shows who
 * made the announcement, which is what a reader wants to know anyway.
 *
 * Notification then rides the existing pipeline: the mention writes an inbox
 * row, sends the desktop alert, and emails people who are away, all subject to
 * each member's own preferences and do-not-disturb hours.
 */
class PublishAnnouncement
{
    public function __construct(
        private readonly SendMessage $send,
        private readonly UpdateMessage $update,
        private readonly DeleteMessage $delete,
    ) {}

    /**
     * @param  array{title: string, body: string, is_pinned: bool}  $attributes
     */
    public function publish(
        Workspace $workspace,
        User $author,
        array $attributes,
        ?Channel $channel = null,
        bool $notifyEveryone = false,
    ): Announcement {
        $announcement = $workspace->announcements()->create([
            ...$attributes,
            'author_id' => $author->id,
            'channel_id' => $channel?->id,
            'notified_everyone' => $channel !== null && $notifyEveryone,
        ]);

        if ($channel === null) {
            return $announcement;
        }

        /*
         * Not inside a transaction with the insert above: SendMessage
         * broadcasts and queues notifications, which must not go out for a
         * message a rollback could still take back. If the post fails, the
         * announcement goes too rather than being left half-published.
         */
        try {
            $message = $this->send->handle(
                container: $channel,
                sender: $author,
                body: $this->body($announcement),
            );
        } catch (Throwable $exception) {
            $announcement->delete();

            throw $exception;
        }

        $announcement->update(['message_id' => $message->id]);

        return $announcement;
    }

    /**
     * Edit the announcement and keep its channel post in step.
     *
     * The post is rewritten in place rather than re-sent: re-sending would
     * notify everyone a second time for a typo fix. A mention that survives the
     * edit keeps its read state — see UpdateMessage.
     *
     * @param  array{title: string, body: string, is_pinned: bool}  $attributes
     */
    public function revise(Announcement $announcement, array $attributes): Announcement
    {
        $announcement->update($attributes);

        $message = $announcement->message;

        if ($message !== null) {
            $this->update->handle($message, $this->body($announcement));
        }

        return $announcement;
    }

    /**
     * Withdraw the announcement, taking its channel post with it so the
     * channel does not go on announcing something HR has retracted.
     */
    public function withdraw(Announcement $announcement, User $actor): void
    {
        $message = $announcement->message;

        if ($message !== null) {
            $this->delete->handle($message, $actor);
        }

        $announcement->delete();
    }

    /**
     * The channel post: the title in bold, the body, the broadcast mention if
     * asked for, and a link back to HR where it stays readable.
     */
    private function body(Announcement $announcement): string
    {
        $lines = [
            '📣 **'.str_replace('*', '', $announcement->title).'**',
            '',
            $announcement->body,
            '',
        ];

        if ($announcement->notified_everyone) {
            $lines[] = '@everyone';
        }

        $lines[] = route('hr.announcements.index', ['workspace' => $announcement->workspace->slug]);

        return implode("\n", $lines);
    }
}
