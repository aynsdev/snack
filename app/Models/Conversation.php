<?php

namespace App\Models;

use App\Enums\ConversationType;
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

/**
 * A direct or group message thread.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property ConversationType $type
 * @property string|null $name
 * @property string $participants_hash
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'created_by', 'type', 'name', 'participants_hash', 'last_message_at'])]
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
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
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
     * @return Collection<int, User>
     */
    public function counterparts(User $viewer): Collection
    {
        return $this->users->reject(fn (User $user): bool => $user->id === $viewer->id)->values();
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
