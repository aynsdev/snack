<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationReason;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;

/**
 * Stamps notifications read.
 *
 * Read state is a timestamp on the row rather than a deletion: the centre is a
 * history, and "mark read" should clear the badge without throwing away what
 * came in. Nothing is broadcast — read state is private, and the only client
 * that needs to know is the one that asked.
 *
 * Every path is scoped by workspace *and* notifiable, so a request naming
 * somebody else's notification id updates zero rows rather than theirs.
 */
class MarkNotificationsRead
{
    /**
     * Mark every unread notification in this workspace read.
     *
     * @return int How many rows were stamped, so the caller can report it.
     */
    public function all(Workspace $workspace, User $user): int
    {
        return Notification::query()
            ->forWorkspace($workspace, $user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Mark one notification read, addressed by its uuid.
     *
     * Already-read rows are left alone rather than re-stamped: opening
     * something twice should not move when it was first seen.
     */
    public function one(Workspace $workspace, User $user, string $notificationId): bool
    {
        return Notification::query()
            ->forWorkspace($workspace, $user)
            ->whereKey($notificationId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }

    /**
     * Mark read every row raised by a message the reader has now seen.
     *
     * Called from the read pointer, so the inbox counts what is still waiting
     * rather than everything that was ever raised. A badge that survives having
     * read the message behind it is one people learn to ignore — and a direct
     * message is the case that makes it unmissable, because every line writes a
     * row, so the badge stayed lit on a conversation already read and answered.
     *
     * # What it deliberately leaves alone
     *
     * Thread replies, whether the row is a `ThreadReply` or a mention inside
     * one. Ids are monotonic, so a reply posted before a later top-level message
     * sits below the container's pointer without the reader ever having opened
     * the thread it lives in. Those rows are cleared by {@see forThread()}, from
     * the panel's own pointer.
     *
     * @param  int  $throughMessageId  The reader's new pointer in the container.
     * @return int How many rows were stamped.
     */
    public function forContainer(
        Workspace $workspace,
        User $user,
        Channel|Conversation $container,
        int $throughMessageId,
    ): int {
        return Notification::query()
            ->forWorkspace($workspace, $user)
            ->whereNull('read_at')
            ->whereReason(NotificationReason::Mention, NotificationReason::DirectMessage)
            /*
             * A channel is addressed by its primary key and a conversation by
             * its opaque public id, which is what ContainerSummary wrote into
             * the payload. Matching the same way App\Services\NotificationCoalescer
             * finds its open rows.
             */
            ->where('data->container->id', $container instanceof Channel
                ? $container->id
                : $container->public_id)
            ->whereNull('data->thread_root_id')
            ->whereRaw("(data->>'message_id')::bigint <= ?", [$throughMessageId])
            ->update(['read_at' => now()]);
    }

    /**
     * The same, for the replies under one thread root.
     *
     * Keyed on the root rather than on the subscription: a mention can pull
     * somebody into a thread they do not follow, and their row has to clear when
     * they read it just the same.
     *
     * @param  int  $throughReplyId  The reader's new pointer within the thread.
     * @return int How many rows were stamped.
     */
    public function forThread(
        Workspace $workspace,
        User $user,
        int $rootMessageId,
        int $throughReplyId,
    ): int {
        return Notification::query()
            ->forWorkspace($workspace, $user)
            ->whereNull('read_at')
            ->where('data->thread_root_id', $rootMessageId)
            ->whereRaw("(data->>'message_id')::bigint <= ?", [$throughReplyId])
            ->update(['read_at' => now()]);
    }
}
