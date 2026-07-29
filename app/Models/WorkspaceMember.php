<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\NotificationLevel;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Joins a user to a workspace and carries their workspace-scoped role.
 *
 * Modelled as a Pivot so it can back both `Workspace::users()` and its own
 * first-class queries.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property MemberStatus $status
 * @property array<string, mixed> $notification_preferences
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'role', 'status', 'notification_preferences', 'joined_at'])]
class WorkspaceMember extends Pivot
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'workspace_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => MemberStatus::class,
            'notification_preferences' => 'array',
            'joined_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWhereRole(Builder $query, WorkspaceRole ...$roles): void
    {
        $query->whereIn('role', array_map(fn (WorkspaceRole $role): string => $role->value, $roles));
    }

    // -- State ---------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isOwner(): bool
    {
        return $this->role === WorkspaceRole::Owner;
    }

    public function isGuest(): bool
    {
        return $this->role->isGuest();
    }

    /**
     * Whether this member outranks another. Used to stop an admin from
     * demoting, suspending, or removing an owner or a peer admin.
     */
    public function outranks(self $other): bool
    {
        return $this->role->rank() > $other->role->rank();
    }

    /**
     * The workspace-wide notification default, applied when a channel
     * membership has not overridden it.
     */
    public function defaultNotificationLevel(): NotificationLevel
    {
        $value = data_get($this->notification_preferences, 'default_level');

        return NotificationLevel::tryFrom((string) $value) ?? NotificationLevel::Mentions;
    }

    /**
     * Do-not-disturb schedule, evaluated against the user's own timezone.
     *
     * @return array{start: string, end: string}|null
     */
    public function doNotDisturbSchedule(): ?array
    {
        $schedule = data_get($this->notification_preferences, 'do_not_disturb');

        if (! is_array($schedule) || ! isset($schedule['start'], $schedule['end'])) {
            return null;
        }

        return ['start' => (string) $schedule['start'], 'end' => (string) $schedule['end']];
    }
}
