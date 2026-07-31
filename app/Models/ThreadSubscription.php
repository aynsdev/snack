<?php

namespace App\Models;

use Database\Factories\ThreadSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person following one thread, with their read pointer into it.
 *
 * The row's existence is the subscription — see the migration for why there is
 * no separate flag. `root_message_id` is always a thread root, so a person has
 * at most one of these per thread no matter how many replies they leave.
 *
 * @property int $id
 * @property int $user_id
 * @property int $root_message_id
 * @property int|null $last_read_reply_id
 * @property Carbon|null $last_read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Message|null $root Null once the root has been soft-deleted:
 *                                   the subscription outlives it so the row can
 *                                   be cleaned up, but there is no thread left.
 */
#[Fillable(['user_id', 'root_message_id', 'last_read_reply_id', 'last_read_at'])]
class ThreadSubscription extends Model
{
    /** @use HasFactory<ThreadSubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

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
    public function root(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'root_message_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Whether a thread whose newest reply is `$lastReplyId` sits above this
     * subscriber's read pointer.
     */
    public function hasUnread(?int $lastReplyId): bool
    {
        return $lastReplyId !== null && $lastReplyId > ($this->last_read_reply_id ?? 0);
    }
}
