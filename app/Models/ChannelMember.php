<?php

namespace App\Models;

use App\Enums\ChannelRole;
use App\Enums\NotificationLevel;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership of one channel, including their read position.
 *
 * `last_read_message_id` is a pointer rather than a per-message read receipt:
 * unread counts are "messages in this channel with a higher id", which is a
 * single index range scan no matter how long the history is.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property ChannelRole $role
 * @property int|null $last_read_message_id
 * @property Carbon|null $last_read_at
 * @property NotificationLevel $notification_level
 * @property bool $is_muted
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 */
#[Fillable([
    'channel_id', 'user_id', 'role', 'last_read_message_id', 'last_read_at',
    'notification_level', 'is_muted', 'joined_at',
])]
class ChannelMember extends Pivot
{
    /** @use HasFactory<ChannelMemberFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'channel_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChannelRole::class,
            'notification_level' => NotificationLevel::class,
            'is_muted' => 'boolean',
            'last_read_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
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
    public function scopeNotMuted(Builder $query): void
    {
        $query->where('is_muted', false);
    }

    // -- State ---------------------------------------------------------------

    public function isChannelAdmin(): bool
    {
        return $this->role->canManageChannel();
    }

    /**
     * Whether an ordinary (non-mentioning) message should notify this member.
     */
    public function wantsNotificationForEveryMessage(): bool
    {
        return ! $this->is_muted && $this->notification_level->notifiesOnEveryMessage();
    }

    /**
     * Whether a message mentioning this member should notify them. Muting a
     * channel silences ordinary traffic but never a direct mention.
     */
    public function wantsNotificationForMention(): bool
    {
        return $this->notification_level->notifiesOnMention();
    }
}
