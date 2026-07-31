<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\ScopedToWorkspace;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, plus the tenant.
 *
 * The framework's payload is `id`, `type`, `data`, `read_at`. Snack's
 * `notifications` table also carries a `workspace_id`, because the inbox and its
 * badge are per-workspace: `notifications_unread_index` is a partial index on
 * `(notifiable, workspace_id, created_at) WHERE read_at IS NULL`, and without
 * the column filled in, every unread count would have to unpack the JSON `data`
 * of every row the user has ever received.
 *
 * Registered over the default in {@see App\Providers\AppServiceProvider}, so
 * `via() => ['database']` writes the tenant automatically and no notification
 * has to remember to.
 */
class WorkspaceDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return [
            ...parent::buildPayload($notifiable, $notification),

            // Null for account-level notifications that have no workspace
            // behind them yet — an invitation to a workspace you are not in.
            'workspace_id' => $notification instanceof ScopedToWorkspace
                ? $notification->workspace()->id
                : null,
        ];
    }
}
