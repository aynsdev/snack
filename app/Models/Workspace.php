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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The shelves this workspace sorts its projects onto.
     *
     * @return HasMany<ProjectGroup, $this>
     */
    public function projectGroups(): HasMany
    {
        return $this->hasMany(ProjectGroup::class);
    }

    /**
     * Every member's channel sidebar sections — one person's at a time in
     * practice, since a section belongs to whoever made it. The relation exists
     * so scoped route binding can tie {channelGroup} to this workspace; who may
     * see one is {@see App\Policies\ChannelGroupPolicy}'s question.
     *
     * @return HasMany<ChannelGroup, $this>
     */
    public function channelGroups(): HasMany
    {
        return $this->hasMany(ChannelGroup::class);
    }

    /**
     * The shelves of this workspace's asset library.
     *
     * Also what `assets/folders/{assetFolder}` scopes through, so a folder id
     * from another tenant 404s at the router rather than reaching a policy that
     * would have to catch it — the same job {@see self::tasks()} does.
     *
     * @return HasMany<AssetFolder, $this>
     */
    public function assetFolders(): HasMany
    {
        return $this->hasMany(AssetFolder::class);
    }

    /**
     * Everything on those shelves, including the things at the top of the
     * library that are on no shelf at all.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * The recurring standups the bot runs in this workspace.
     *
     * @return HasMany<Standup, $this>
     */
    public function standups(): HasMany
    {
        return $this->hasMany(Standup::class);
    }

    /**
     * The incoming webhooks pointed at this workspace's channels.
     *
     * Also what `webhooks/{webhook}` scopes through, so an id from another
     * tenant 404s at the router rather than at a policy — the same job
     * {@see self::tasks()} does for `tasks/{task}`.
     *
     * @return HasMany<IncomingWebhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(IncomingWebhook::class);
    }

    /**
     * Every task in every project of this workspace.
     *
     * Exists for the route binding rather than for reading: `tasks/{task}` sits
     * inside the workspace's `scopeBindings()` group, and this is the relation
     * that scoping resolves through — so a task id from another tenant 404s at
     * the router instead of reaching a policy that would have to catch it.
     *
     * The same job Workspace::messages() does for `messages/{message}`.
     *
     * @return HasManyThrough<Task, Project, $this>
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Project::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Every file uploaded into this workspace, attached or not.
     *
     * Exists so the file routes can be scope-bound: `w/{workspace}/files/{attachment}`
     * resolves through here, which means an id from another tenant is a 404 at
     * the router rather than something a controller has to remember to check.
     * Whether the *viewer* may see a given file is a separate question, settled
     * by the message's container policy.
     *
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
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
        return $this->humanMemberCount() >= $this->member_limit;
    }

    /**
     * The people who count against the seat limit.
     *
     * Bots do not. The standup app holds a membership row because it has to —
     * it sends direct messages, and a sender that is not in the workspace is
     * one no policy will let through — but it is not a colleague, and a
     * workspace that installs it should not lose a seat for the privilege.
     */
    public function humanMemberCount(): int
    {
        return $this->members()
            ->whereHas('user', fn ($query) => $query->where('is_bot', false))
            ->count();
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

        // The configured disk, for the reason {@see User::avatarUrl()} reads it:
        // the write side already did, and the two disagreeing meant a logo on
        // object storage was requested from the local public disk.
        return Storage::disk((string) config('snack.avatars.disk', 'public'))
            ->url($this->logo_path);
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
