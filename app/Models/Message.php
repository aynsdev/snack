<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property int|null $last_reply_id
 * @property bool $has_attachments
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $sender
 * @property-read int|null $agent_task_id
 * @property-read Task|null $agentTask
 */
#[Fillable([
    'workspace_id', 'sender_id', 'channel_id', 'conversation_id', 'parent_message_id',
    'body', 'body_plaintext', 'type', 'metadata', 'has_attachments', 'edited_at',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    private const AGENT_MARKER_SUFFIX = '/\s*‹la:[a-z0-9]{8}›$/u';

    /**
     * ts_headline drops the closing `›` as punctuation, so a search snippet
     * ends in a marker this loose.
     */
    private const AGENT_SNIPPET_MARKER_SUFFIX = '/\s*‹la:[a-z0-9]{0,8}›?$/u';

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
     * The task an agent's plan belongs to, keyed through its metadata — see
     * {@see agentTaskId()}. Only ever eager-loaded: by the thread view, and
     * before a plan is broadcast.
     *
     * @return BelongsTo<Task, $this>
     */
    public function agentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'agent_task_id');
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function agentTaskId(): Attribute
    {
        return Attribute::make(get: function (): ?int {
            $agent = $this->metadata['agent'] ?? null;

            return is_array($agent) && ($agent['kind'] ?? null) === 'plan' && is_int($agent['task_id'] ?? null)
                ? $agent['task_id']
                : null;
        });
    }

    public function loadAgentTask(): static
    {
        return $this->agent_task_id === null ? $this : $this->load('agentTask:id,assignee_id');
    }

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

    /**
     * Who is following this thread. Only ever populated on a thread root.
     *
     * @return HasMany<ThreadSubscription, $this>
     */
    public function threadSubscriptions(): HasMany
    {
        return $this->hasMany(ThreadSubscription::class, 'root_message_id');
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
     * @param  User|null  $viewer  Whose saves to load. A save is private, so it
     *                             is loaded per-viewer rather than wholesale —
     *                             without this the relation would carry every
     *                             user who bookmarked the message.
     */
    public function scopeWithTimelineRelations(Builder $query, ?User $viewer = null): void
    {
        $viewer ??= request()->user();

        $query->with([
            'sender:id,name,username,avatar_path,title,presence_status',
            // The reactor's name comes along because the pill's tooltip names
            // who reacted. Nested here rather than resolved per pill, which
            // would be an N+1 hiding inside the resource.
            'reactions:id,message_id,user_id,emoji',
            'reactions.user:id,name',
            'attachments',
            'pin:id,message_id,pinned_by',

            /*
             * Loaded *empty* rather than skipped for an unauthenticated request:
             * an unloaded relation would be re-queried by the resource for every
             * message, which is the N+1 this eager load exists to prevent.
             */
            'saves' => fn ($saves) => $viewer === null
                ? $saves->whereRaw('1 = 0')
                : $saves->where('user_id', $viewer->id),
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
        return str($this->withoutAgentMarker($this->body_plaintext))->squish()->limit($length)->toString();
    }

    public function isAgentPost(): bool
    {
        return is_array($this->metadata['agent'] ?? null);
    }

    /**
     * The body as readers see it. The stored body keeps the agent's dedupe
     * marker because agent_find_post matches on it.
     */
    public function displayBody(): string
    {
        return $this->withoutAgentMarker($this->body);
    }

    public function withoutAgentMarker(string $text): string
    {
        return $this->stripAgentSuffix(self::AGENT_MARKER_SUFFIX, $text);
    }

    public function searchSnippetWithoutAgentMarker(string $snippet): string
    {
        return $this->stripAgentSuffix(self::AGENT_SNIPPET_MARKER_SUFFIX, $snippet);
    }

    private function stripAgentSuffix(string $pattern, string $text): string
    {
        if (! $this->isAgentPost()) {
            return $text;
        }

        return preg_replace($pattern, '', $text) ?? $text;
    }
}
