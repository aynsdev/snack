<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Services\Outbound\AgentSettingsEvents;
use App\Support\OneLine;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, many-to-many conversation space inside a workspace.
 *
 * `is_private` is derived from `type`: a PostgreSQL generated column in the
 * database (so the partial indexes can use it) and a read-only accessor here.
 * It is never writable, which is why it is absent from the fillable list.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $topic
 * @property ChannelType $type
 * @property bool $is_private
 * @property bool $is_archived
 * @property bool $is_default
 * @property Carbon|null $last_message_at
 * @property int $message_count
 * @property array<string, mixed> $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Collection<int, Canvas> $canvases
 */
#[Fillable([
    'workspace_id', 'created_by', 'name', 'slug', 'description', 'topic',
    'type', 'is_archived', 'is_default', 'last_message_at', 'settings',
])]
#[RouteKey('slug')]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'is_archived' => 'boolean',
            'is_default' => 'boolean',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * Kept to one line however it arrives — see {@see OneLine}.
     *
     * @return Attribute<string, string|null>
     */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => OneLine::cleanOrNull($value));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function topic(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => OneLine::cleanOrNull($value));
    }

    /**
     * A channel some project's agents talk in is cleared from those projects —
     * and the agent dispatcher told so — in the same transaction as the
     * delete. The foreign key would clear it anyway (`nullOnDelete`), but
     * silently, and the dispatcher would keep routing threads to a channel
     * that no longer exists.
     *
     * Nothing deletes a channel today. A workspace's cascade bypasses this,
     * and takes the projects with it.
     */
    public function delete(): ?bool
    {
        return app(AgentSettingsEvents::class)->aroundChannelChange($this, function (array $projectIds): ?bool {
            Project::query()->whereKey($projectIds)->update(['agent_channel_id' => null]);

            return parent::delete();
        });
    }

    /**
     * Privacy, derived from the channel type.
     *
     * The database has a matching generated column, which is what the partial
     * indexes are built on — but PostgreSQL computes it during INSERT and does
     * not send it back, so a freshly created model would otherwise report
     * `null` here. Reading `null` as "not private" is the worst possible
     * failure mode: it would have announced a brand-new private channel to the
     * entire workspace. Computing it in PHP makes the model correct from the
     * moment it is instantiated, refreshed or not.
     *
     * @return Attribute<bool, never>
     */
    protected function isPrivate(): Attribute
    {
        return Attribute::get(fn (): bool => $this->type->isPrivate())
            ->shouldCache();
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ChannelMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ChannelMember::class);
    }

    /**
     * @return BelongsToMany<User, $this, ChannelMember>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')
            ->using(ChannelMember::class)
            ->withPivot(['role', 'notification_level', 'is_muted', 'last_read_message_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Canvas, $this> */
    public function canvases(): HasMany
    {
        return $this->hasMany(Canvas::class)->orderBy('id');
    }

    /**
     * @return HasMany<PinnedMessage, $this>
     */
    public function pinnedMessages(): HasMany
    {
        return $this->hasMany(PinnedMessage::class);
    }

    /**
     * The standups whose roster is this channel's membership and whose summary
     * is posted here.
     *
     * @return HasMany<Standup, $this>
     */
    public function standups(): HasMany
    {
        return $this->hasMany(Standup::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    /**
     * Channels that appear in the channel browser: public and announcement
     * channels that have not been archived.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBrowsable(Builder $query): void
    {
        $query->where('is_private', false)->where('is_archived', false);
    }

    /**
     * Every channel the user is allowed to see in this workspace.
     *
     * Guests are confined to their explicit memberships; everyone else can also
     * see public and announcement channels they have not joined yet. This is
     * the single source of truth for channel visibility and is reused by the
     * sidebar, the browser, and search.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, WorkspaceRole $role): void
    {
        $memberOf = fn (Builder $inner) => $inner->whereHas(
            'members',
            fn (Builder $members) => $members->where('user_id', $user->id),
        );

        if ($role->isGuest()) {
            $memberOf($query);

            return;
        }

        $query->where(fn (Builder $visible) => $visible
            ->where('is_private', false)
            ->orWhere($memberOf),
        );
    }

    /**
     * The channels a person may point a project's agents at: live,
     * non-announcement channels they can see. Choosing one decides which
     * conversations are sent to the agent dispatcher, so nobody may choose a
     * channel they were never in — and agents need to be able to post there.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeChoosableAsAgentChannel(Builder $query, User $user, WorkspaceRole $role): void
    {
        $query->where('type', '!=', ChannelType::Announcement)
            ->notArchived()
            ->visibleTo($user, $role);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWhereMember(Builder $query, User $user): void
    {
        $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
    }

    // -- Membership ----------------------------------------------------------

    public function membershipFor(User $user): ?ChannelMember
    {
        if ($this->relationLoaded('members')) {
            return $this->members->firstWhere('user_id', $user->id);
        }

        return $this->members()->where('user_id', $user->id)->first();
    }

    public function hasMember(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    // -- State ---------------------------------------------------------------

    /**
     * Whether a workspace member who has not joined may still read the history.
     * Private channels always require membership.
     */
    public function isPreviewableBy(WorkspaceRole $role): bool
    {
        return ! $this->is_private && ! $role->isGuest();
    }

    /**
     * Announcement channels restrict posting to workspace admins; every other
     * channel type lets any joined member post.
     */
    public function allowsPostingBy(WorkspaceRole $role): bool
    {
        if ($this->is_archived) {
            return false;
        }

        if ($this->type->requiresElevatedPostingRights()) {
            return $role->canAdministerWorkspace();
        }

        return true;
    }

    /**
     * Channel reference used in message bodies and copied links.
     */
    public function mentionHandle(): string
    {
        return '#'.$this->slug;
    }
}
