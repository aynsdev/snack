<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Policies\ConversationPolicy;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A direct or group message thread.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property ConversationType $type
 * @property string|null $name
 * @property string $participants_hash
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Canvas> $canvases
 */
#[Fillable(['workspace_id', 'created_by', 'public_id', 'type', 'name', 'participants_hash', 'last_message_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Assigned here rather than left to callers, so there is no path — a
        // factory, a seeder, a future action — that can create a conversation
        // the router cannot address.
        static::creating(function (self $conversation): void {
            $conversation->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * Conversations are addressed by their opaque `public_id`, never by their
     * primary key.
     *
     * `/dms/1` would advertise that `/dms/2` exists and let anyone measure how
     * many private conversations the deployment holds. Every such request is
     * refused by {@see ConversationPolicy}, but the count is a
     * fact worth not publishing in the first place.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Deterministic digest of a participant set.
     *
     * Paired with the partial unique index on (workspace_id,
     * participants_hash) WHERE type = 'direct', this is what actually prevents
     * two people from ending up with duplicate one-to-one conversations. An
     * application-level "find or create" alone would race.
     *
     * @param  iterable<int, int>  $userIds
     */
    public static function hashParticipants(iterable $userIds): string
    {
        $ids = collect($userIds)
            ->map(fn (int $id): int => $id)
            ->unique()
            ->sort()
            ->values()
            ->implode('-');

        return hash('sha256', $ids);
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
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * Everyone who has ever been in the conversation, including those who left.
     *
     * @return BelongsToMany<User, $this, ConversationParticipant>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['notification_level', 'is_muted', 'last_read_message_id', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * The current roster, which is what every header, title, and avatar stack
     * renders.
     *
     * A separate relation from {@see participants()} on purpose. `participants`
     * is frequently loaded constrained to one viewer — that is what keeps the
     * policy checks over a page of messages from becoming an N+1 — so reading a
     * roster from it would silently yield a conversation with one person in it.
     * Anything that displays *who is here* asks for this instead.
     *
     * @return BelongsToMany<User, $this, ConversationParticipant>
     */
    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivotNull('left_at');
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

    // -- Scopes --------------------------------------------------------------

    /**
     * Conversations the user currently participates in.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('participants', fn (Builder $participants) => $participants
            ->where('user_id', $user->id)
            ->whereNull('left_at'),
        );
    }

    // -- Participation -------------------------------------------------------

    public function participantFor(User $user): ?ConversationParticipant
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->firstWhere('user_id', $user->id);
        }

        return $this->participants()->where('user_id', $user->id)->first();
    }

    /**
     * Whether the user may currently read and post. Someone who left a group
     * conversation keeps read access to history via the policy, but is no
     * longer an active participant.
     */
    public function hasActiveParticipant(User $user): bool
    {
        return $this->participantFor($user)?->hasLeft() === false;
    }

    /**
     * Everyone except the viewer, used to title a conversation in the sidebar.
     *
     * Reads the loaded {@see activeUsers()} relation, so callers are expected to
     * have eager-loaded it; resolving it here per conversation would be an N+1
     * across the whole DM list.
     *
     * @return Collection<int, User>
     */
    public function counterparts(User $viewer): Collection
    {
        return $this->activeUsers->reject(fn (User $user): bool => $user->id === $viewer->id)->values();
    }

    /**
     * The label shown in the sidebar: an explicit group name when set,
     * otherwise the other participants' names.
     */
    public function displayNameFor(User $viewer): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        $others = $this->counterparts($viewer);

        if ($others->isEmpty()) {
            return 'Just you';
        }

        return $others->map(fn (User $user): string => $user->name)->join(', ', ' and ');
    }
}
