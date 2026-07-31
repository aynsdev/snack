<?php

namespace App\Models;

use App\Enums\NotificationLevel;
use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's participation in one conversation, including their read position.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 * @property Carbon|null $last_read_at
 * @property NotificationLevel $notification_level
 * @property bool $is_muted
 * @property Carbon|null $left_at
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 * @property-read User $user
 */
#[Fillable([
    'conversation_id', 'user_id', 'last_read_message_id', 'last_read_at',
    'notification_level', 'is_muted', 'left_at', 'joined_at',
])]
class ConversationParticipant extends Pivot
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'conversation_participants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_level' => NotificationLevel::class,
            'is_muted' => 'boolean',
            'last_read_at' => 'datetime',
            'left_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    // -- State ---------------------------------------------------------------

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    /**
     * Direct messages default to notifying on everything, so muting is the only
     * thing that can silence them.
     */
    public function wantsNotificationForEveryMessage(): bool
    {
        return ! $this->is_muted
            && ! $this->hasLeft()
            && $this->notification_level->notifiesOnEveryMessage();
    }

    public function wantsNotificationForMention(): bool
    {
        return ! $this->hasLeft() && $this->notification_level->notifiesOnMention();
    }

    /**
     * Whether a reply in a thread this participant follows should notify them.
     *
     * Mirrors {@see ChannelMember::wantsNotificationForThreadReply()}: following
     * a thread outranks the container's broad level, but not a mute.
     */
    public function wantsNotificationForThreadReply(): bool
    {
        return ! $this->is_muted
            && ! $this->hasLeft()
            && $this->notification_level->notifiesOnMention();
    }
}
