<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A message in a channel or a conversation, optionally a reply within a thread.
 *
 * A message belongs to exactly one container, which the database enforces with
 * `num_nonnulls(channel_id, conversation_id) = 1` rather than trusting callers.
 *
 * Soft deletes preserve thread structure: deleting a parent must not orphan its
 * replies, and moderation needs the original row to remain auditable.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $sender_id
 * @property int|null $channel_id
 * @property int|null $conversation_id
 * @property int|null $parent_message_id
 * @property string $body
 * @property string $body_plaintext
 * @property MessageType $type
 * @property array<string, mixed> $metadata
 * @property int $reply_count
 * @property Carbon|null $last_reply_at
 * @property bool $has_attachments
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $sender
 */
#[Fillable([
    'workspace_id', 'sender_id', 'channel_id', 'conversation_id', 'parent_message_id',
    'body', 'body_plaintext', 'type', 'metadata', 'has_attachments', 'edited_at',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'metadata' => 'array',
            'reply_count' => 'integer',
            'has_attachments' => 'boolean',
            'last_reply_at' => 'datetime',
            'edited_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
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
     * @return BelongsTo<Message, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }

    /**
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return HasMany<Mention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * @return HasOne<PinnedMessage, $this>
     */
    public function pin(): HasOne
    {
        return $this->hasOne(PinnedMessage::class);
    }

    /**
     * @return HasMany<SavedMessage, $this>
     */
    public function saves(): HasMany
    {
        return $this->hasMany(SavedMessage::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Timeline messages only: thread replies are fetched through the thread
     * panel and would otherwise duplicate in the main history.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeRootLevel(Builder $query): void
    {
        $query->whereNull('parent_message_id');
    }

    /**
     * Cursor pagination over the immutable, monotonic primary key.
     *
     * Offset pagination would skip or repeat messages whenever anything is
     * posted mid-scroll, which in a live chat is constantly.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBefore(Builder $query, ?int $cursor): void
    {
        $query->when($cursor !== null, fn (Builder $inner) => $inner->where('id', '<', $cursor));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAfter(Builder $query, ?int $cursor): void
    {
        $query->when($cursor !== null, fn (Builder $inner) => $inner->where('id', '>', $cursor));
    }

    /**
     * Eager loads everything the message list renders, so a page of history is
     * a fixed number of queries regardless of page size.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithTimelineRelations(Builder $query): void
    {
        $query->with([
            'sender:id,name,username,avatar_path,title,presence_status',
            'reactions:id,message_id,user_id,emoji',
            'attachments',
            'pin:id,message_id,pinned_by',
        ]);
    }

    // -- State ---------------------------------------------------------------

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isThreadReply(): bool
    {
        return $this->parent_message_id !== null;
    }

    public function hasThread(): bool
    {
        return $this->reply_count > 0;
    }

    public function isInChannel(): bool
    {
        return $this->channel_id !== null;
    }

    public function wasSentBy(User $user): bool
    {
        return $this->sender_id !== null && $this->sender_id === $user->id;
    }

    /**
     * The channel or conversation this message lives in.
     */
    public function container(): Channel|Conversation|null
    {
        return $this->isInChannel() ? $this->channel : $this->conversation;
    }

    /**
     * The thread root: the parent for a reply, or the message itself.
     */
    public function threadRootId(): int
    {
        return $this->parent_message_id ?? $this->id;
    }

    /**
     * Preview text for sidebars, notifications, and search results.
     */
    public function preview(int $length = 120): string
    {
        return str($this->body_plaintext)->squish()->limit($length)->toString();
    }
}
