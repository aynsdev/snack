<?php

namespace App\Models;

use App\Enums\MentionType;
use Database\Factories\MentionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A materialized mention, written when the message is sent.
 *
 * Resolving mentions eagerly means the unread-mention badge is a partial index
 * scan rather than a full-text search across message bodies on every page load.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $message_id
 * @property int $mentioned_user_id
 * @property MentionType $mention_type
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 */
#[Fillable(['workspace_id', 'message_id', 'mentioned_user_id', 'mention_type', 'read_at'])]
class Mention extends Model
{
    /** @use HasFactory<MentionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mention_type' => MentionType::class,
            'read_at' => 'datetime',
        ];
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
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFor(Builder $query, User $user, Workspace $workspace): void
    {
        $query->where('mentioned_user_id', $user->id)
            ->where('workspace_id', $workspace->id);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
