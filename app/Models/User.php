<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $username
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar_path
 * @property string|null $title
 * @property string|null $department
 * @property string $timezone
 * @property string|null $status_message
 * @property string|null $status_emoji
 * @property Carbon|null $status_expires_at
 * @property PresenceStatus $presence_status
 * @property Carbon|null $last_seen_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'username', 'password', 'avatar_path', 'title', 'department',
    'timezone', 'status_message', 'status_emoji', 'status_expires_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirrors the column default so a just-created instance already carries a
     * presence: registration hands that in-memory model straight to the guard,
     * and everything that renders a person reads `presence_status`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'presence_status' => PresenceStatus::Offline->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'status_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'presence_status' => PresenceStatus::class,
        ];
    }

    // -- Workspaces ----------------------------------------------------------

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * Workspaces this user is an active member of. Suspended memberships are
     * excluded, so a suspended account simply stops seeing the workspace.
     *
     * @return BelongsToMany<Workspace, $this, WorkspaceMember>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps()
            ->wherePivot('status', MemberStatus::Active->value);
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    // -- Channels and conversations ------------------------------------------

    /**
     * @return HasMany<ChannelMember, $this>
     */
    public function channelMemberships(): HasMany
    {
        return $this->hasMany(ChannelMember::class);
    }

    /**
     * @return BelongsToMany<Channel, $this, ChannelMember>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_members')
            ->using(ChannelMember::class)
            ->withPivot(['role', 'notification_level', 'is_muted', 'last_read_message_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The projects this person is on — which is also the set they can be
     * assigned work in.
     *
     * @return BelongsToMany<Project, $this, ProjectMember>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Conversation, $this, ConversationParticipant>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['notification_level', 'is_muted', 'last_read_message_id', 'joined_at', 'left_at'])
            ->withTimestamps()
            ->wherePivotNull('left_at');
    }

    // -- Messages ------------------------------------------------------------

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * @return HasMany<Mention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class, 'mentioned_user_id');
    }

    /**
     * @return HasMany<SavedMessage, $this>
     */
    public function savedMessages(): HasMany
    {
        return $this->hasMany(SavedMessage::class);
    }

    // -- Notifications -------------------------------------------------------

    /**
     * Overrides the Notifiable trait's relation so both reads *and* writes go
     * through Snack's own model rather than the framework's.
     *
     * The database channel resolves the target table from this relation, so
     * pointing it here is what lets {@see Notification} add the workspace scope
     * and the reason accessor without a second, parallel model.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    // -- Membership helpers --------------------------------------------------

    public function membershipIn(Workspace $workspace): ?WorkspaceMember
    {
        if ($this->relationLoaded('workspaceMemberships')) {
            return $this->workspaceMemberships->firstWhere('workspace_id', $workspace->id);
        }

        return $this->workspaceMemberships()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * The user's role in a workspace, or null when they are not an active
     * member. Every authorization decision starts here.
     */
    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        $membership = $this->membershipIn($workspace);

        return $membership?->isActive() === true ? $membership->role : null;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->roleIn($workspace) !== null;
    }

    // -- Presence and status -------------------------------------------------

    /**
     * The status message, honouring its expiry: a stale "Out for lunch" from
     * three days ago should not still be showing.
     */
    public function currentStatusMessage(): ?string
    {
        return $this->hasExpiredStatus() ? null : $this->status_message;
    }

    public function currentStatusEmoji(): ?string
    {
        return $this->hasExpiredStatus() ? null : $this->status_emoji;
    }

    private function hasExpiredStatus(): bool
    {
        return $this->status_expires_at?->isPast() === true;
    }

    // -- Presentation --------------------------------------------------------

    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Fallback monogram rendered when the user has no avatar.
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

    public function mentionHandle(): string
    {
        return '@'.$this->username;
    }
}
