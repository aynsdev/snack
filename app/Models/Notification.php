<?php

namespace App\Models;

use App\Enums\NotificationReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * One entry in a user's notification centre.
 *
 * Extends Laravel's `DatabaseNotification` — same table, same uuid key, same
 * `read_at` semantics — and adds the workspace the entry belongs to, which is
 * what makes the inbox and its badge per-tenant rather than global.
 *
 * `type` holds a {@see NotificationReason} value rather than a class name. See
 * that enum for why, and for why an ordinary channel message never lands here.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property int|null $workspace_id
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $workspace
 */
class Notification extends DatabaseNotification
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * The one query the badge and the inbox both start from.
     *
     * Column order matches `notifications_unread_index` so an unread count is an
     * index-only scan bounded by what is actually unread, rather than by how
     * much the user has ever received.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForWorkspace(Builder $query, Workspace $workspace, User $user): void
    {
        $query->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('workspace_id', $workspace->id);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWhereReason(Builder $query, NotificationReason ...$reasons): void
    {
        $query->whereIn('type', array_column($reasons, 'value'));
    }

    // -- State ---------------------------------------------------------------

    /**
     * The reason this was raised, or null for a row written by something other
     * than Snack's own notifications — a package's, or one predating the enum.
     */
    public function reason(): ?NotificationReason
    {
        return NotificationReason::tryFrom($this->type);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
