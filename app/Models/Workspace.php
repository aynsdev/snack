<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\ResolveWorkspace;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant root. Every Snack record is reachable from exactly one workspace.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $description
 * @property int $member_limit
 * @property array<string, mixed> $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['owner_id', 'name', 'slug', 'logo_path', 'description', 'member_limit', 'settings'])]
#[RouteKey('slug')]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_limit' => 'integer',
            'settings' => 'array',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * Everyone with a membership row, including suspended members.
     *
     * Prefer {@see activeUsers()} for anything a member can act on. Suspended
     * people still appear here because the admin roster has to show them in
     * order to reinstate them.
     *
     * @return BelongsToMany<User, $this, WorkspaceMember>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The people who can currently be interacted with.
     *
     * A suspended member is treated exactly like a non-member everywhere in
     * Snack — {@see ResolveWorkspace} closes every door on
     * them — so anything that offers or resolves *people* must use this rather
     * than {@see users()}. Offering a suspended colleague in a picker produces a
     * choice the server then refuses, which reads as a broken feature.
     *
     * @return BelongsToMany<User, $this, WorkspaceMember>
     */
    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MemberStatus::Active->value);
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Restrict to workspaces the given user actively belongs to.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWhereMember(Builder $query, User $user): void
    {
        $query->whereHas('members', fn (Builder $members) => $members
            ->where('user_id', $user->id)
            ->where('status', MemberStatus::Active),
        );
    }

    // -- Membership ----------------------------------------------------------

    /**
     * The membership row for a user, or null when they do not belong here.
     *
     * Reads from the loaded relation when available, because the workspace
     * middleware eager-loads the current user's membership on every request.
     */
    public function membershipFor(User $user): ?WorkspaceMember
    {
        if ($this->relationLoaded('members')) {
            return $this->members->firstWhere('user_id', $user->id);
        }

        return $this->members()->where('user_id', $user->id)->first();
    }

    /**
     * Whether the user is a member in good standing. Suspended members are
     * deliberately treated as non-members for every access decision.
     */
    public function hasActiveMember(User $user): bool
    {
        return $this->membershipFor($user)?->isActive() ?? false;
    }

    public function roleFor(User $user): ?WorkspaceRole
    {
        $membership = $this->membershipFor($user);

        return $membership?->isActive() === true ? $membership->role : null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasReachedMemberLimit(): bool
    {
        return $this->members()->count() >= $this->member_limit;
    }

    // -- Settings ------------------------------------------------------------

    /**
     * Read a workspace setting using dot notation.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * Whether members may join public channels on their own. Turning this off
     * makes every channel effectively invite-only.
     */
    public function allowsSelfServeChannelJoins(): bool
    {
        return (bool) $this->setting('channels.allow_self_serve_joins', true);
    }

    /**
     * Whether admins have disabled invitations for this workspace, e.g. while
     * onboarding is frozen.
     */
    public function allowsInvitations(): bool
    {
        return (bool) $this->setting('auth.allow_invitations', true);
    }

    // -- Presentation --------------------------------------------------------

    public function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * The monogram shown in the workspace rail when no logo is set.
     */
    public function initials(): string
    {
        return str($this->name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word): string => str($word)->substr(0, 1)->upper()->toString())
            ->implode('');
    }
}
