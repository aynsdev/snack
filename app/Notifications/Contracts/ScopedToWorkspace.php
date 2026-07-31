<?php

namespace App\Notifications\Contracts;

use App\Models\Workspace;

/**
 * A notification that belongs to one workspace.
 *
 * Laravel's database channel writes `id`, `type`, `data`, and `read_at` and
 * knows nothing else about the row. Snack's `notifications` table carries a
 * `workspace_id` as well — it is what the unread badge's partial index is keyed
 * on, and what stops a notification raised in one workspace appearing in
 * another's inbox.
 *
 * Implementing this is what lets {@see App\Notifications\Channels\WorkspaceDatabaseChannel}
 * fill that column in. A notification that does not implement it still stores
 * fine, with a null workspace, which is right for account-level mail such as an
 * invitation that has no workspace membership behind it yet.
 */
interface ScopedToWorkspace
{
    public function workspace(): Workspace;
}
