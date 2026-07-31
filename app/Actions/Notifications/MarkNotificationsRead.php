<?php

namespace App\Actions\Notifications;

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
}
