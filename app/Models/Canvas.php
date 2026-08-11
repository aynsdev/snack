<?php

namespace App\Models;

use Database\Factories\CanvasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A workspace document for knowledge that should outlive a conversation.
 *
 * The body is author source text, not HTML. Rendering it through Snack's safe
 * formatter keeps hostile markup inert and leaves room for richer blocks later.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $channel_id
 * @property int|null $conversation_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Channel|null $channel
 * @property-read Conversation|null $conversation
 * @property-read User|null $creator
 * @property-read User|null $editor
 * @property-read MessageAttachment|null $cover
 */
#[Fillable([
    'public_id', 'workspace_id', 'channel_id', 'conversation_id', 'created_by',
    'updated_by', 'title', 'body', 'content',
])]
class Canvas extends Model
{
    /** @use HasFactory<CanvasFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $canvas): void {
            $canvas->public_id ??= (string) Str::ulid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasOne<MessageAttachment, $this> */
    public function cover(): HasOne
    {
        return $this->hasOne(MessageAttachment::class);
    }

    /** @return HasMany<FileCanvasStar, $this> */
    public function stars(): HasMany
    {
        return $this->hasMany(FileCanvasStar::class);
    }
}
