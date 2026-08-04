<?php

namespace App\Models;

use App\Policies\HuddlePolicy;
/*
 * The datetime casts below yield CarbonImmutable rather than Carbon, because
 * AppServiceProvider sets `Date::use(CarbonImmutable::class)` application-wide.
 * Typed honestly here so the `??=` defaults in booted() type-check; the older
 * models predate the check and still say Carbon.
 */
use Carbon\CarbonImmutable;
use Database\Factories\HuddleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A live audio/video session inside a channel or a conversation.
 *
 * A huddle is the lightest possible meeting: it has no title, no schedule, no
 * invitation and no URL of its own. It exists where the conversation already
 * is, anyone who could post there can walk into it, and it ends by itself when
 * the last person leaves.
 *
 * # The room name
 *
 * `public_id` is both the route key and the LiveKit room name. LiveKit is a
 * separate server that Laravel does not sit in front of: it admits anyone
 * holding a validly signed token for a room, so the room's *name* must be
 * unguessable and meaningless. A ULID is both. Naming rooms after the container
 * — `channel-7` — would let anyone who could mint a token for their own huddle
 * dial into somebody else's.
 *
 * # Live vs. finished
 *
 * `ended_at IS NULL` is the whole definition of "live", and two partial unique
 * indexes on it are what guarantee one live huddle per container. See the
 * migration: the race between two people pressing Huddle at the same moment is
 * the ordinary case, and it is settled by the database rather than by hope.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $channel_id
 * @property int|null $conversation_id
 * @property int|null $started_by
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable([
    'public_id', 'workspace_id', 'channel_id', 'conversation_id',
    'started_by', 'started_at', 'ended_at',
])]
class Huddle extends Model
{
    /** @use HasFactory<HuddleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Assigned here rather than left to callers, for the reason a
        // conversation's is: there must be no path — factory, seeder, or a
        // future action — that can create a huddle with no room to dial.
        static::creating(function (self $huddle): void {
            $huddle->public_id ??= (string) Str::ulid();
            $huddle->started_at ??= now();
        });
    }

    /**
     * Huddles are addressed by their opaque `public_id`, never by their primary
     * key — the same rule conversations follow, and for the stronger reason
     * given in the class docblock: this identifier is also what the media server
     * knows the room by.
     *
     * Every request naming one is still re-authorized by {@see HuddlePolicy}.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
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
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Every visit to this huddle, including finished ones.
     *
     * @return HasMany<HuddleParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(HuddleParticipant::class);
    }

    /**
     * The current roster: people who are in the huddle right now.
     *
     * A separate relation from {@see participants()} for the reason
     * {@see Conversation::activeUsers()} is separate — anything that renders
     * *who is here* must not accidentally read the history and report somebody
     * who hung up ten minutes ago as still talking.
     *
     * @return HasMany<HuddleParticipant, $this>
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Huddles that have not ended.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Huddles attached to one container.
     *
     * Written as a single scope taking either container rather than two, so a
     * caller cannot pass a channel and query the conversation column — which
     * would silently match every huddle whose `conversation_id` is null.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForContainer(Builder $query, Channel|Conversation $container): void
    {
        $query->where(
            $container instanceof Channel ? 'channel_id' : 'conversation_id',
            $container->id,
        );
    }

    // -- State ---------------------------------------------------------------

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    public function isInChannel(): bool
    {
        return $this->channel_id !== null;
    }

    /**
     * The channel or conversation this huddle is happening in.
     *
     * Nullable because the relation may simply not be loaded on a partially
     * hydrated instance; a huddle always has exactly one container, which the
     * `huddles_single_container_check` constraint enforces.
     */
    public function container(): Channel|Conversation|null
    {
        return $this->isInChannel() ? $this->channel : $this->conversation;
    }

    /**
     * The LiveKit room. Identical to the public id by definition — named as a
     * method so call sites read as what they mean, and so the two could diverge
     * later without every caller having to.
     */
    public function roomName(): string
    {
        return $this->public_id;
    }

    /**
     * Whether somebody is in this huddle right now.
     *
     * Reads the loaded roster when there is one, so a page that already
     * eager-loaded `activeParticipants` does not pay a query per viewer.
     */
    public function hasActiveParticipant(User $user): bool
    {
        if ($this->relationLoaded('activeParticipants')) {
            return $this->activeParticipants->contains('user_id', $user->id);
        }

        return $this->activeParticipants()->where('user_id', $user->id)->exists();
    }
}
