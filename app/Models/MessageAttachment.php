<?php

namespace App\Models;

use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to a message.
 *
 * `disk`, `path`, and `thumbnail_path` are hidden from serialization: the
 * client only ever receives signed, authorization-checked download URLs, never
 * the storage key itself.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $message_id
 * @property int|null $task_id
 * @property int|null $asset_id
 * @property int $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property array<string, mixed> $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message|null $message
 * @property-read Task|null $task
 * @property-read Asset|null $asset
 */
#[Fillable([
    'workspace_id', 'message_id', 'task_id', 'asset_id', 'uploaded_by', 'disk', 'path',
    'thumbnail_path', 'original_name', 'mime_type', 'size', 'metadata',
])]
#[Hidden(['disk', 'path', 'thumbnail_path'])]
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
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
     * The card this file is attached to, for the files that hang off a task
     * rather than a message. Never both — see the migration's check constraint.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The library entry these bytes are the file behind.
     *
     * The third and last owner an attachment can have. Unlike the other two it
     * is written at the same moment the row is created — a library upload names
     * its destination up front, so there is no window in which the file is
     * unclaimed and nothing for the orphan sweep to race with.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Uploads nothing ever claimed, eligible for pruning.
     *
     * Every owner has to be null. A file on a card has no message, and a file in
     * the asset library has neither — both would otherwise look abandoned to
     * `snack:prune-uploads`, which would delete them within the day. This is the
     * reason an asset's ownership is recorded on *this* row rather than on the
     * `assets` row: the sweep excludes it by construction.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrphaned(Builder $query): void
    {
        $query->whereNull('message_id')->whereNull('task_id')->whereNull('asset_id');
    }

    /**
     * Files that made it onto a sent message. The only ones anybody other than
     * the uploader has any business seeing.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAttached(Builder $query): void
    {
        $query->whereNotNull('message_id');
    }

    /**
     * The file browser's type filter, expressed against MIME prefixes so the
     * grouping matches {@see self::kind()} exactly.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        self::constrainToKind($query, $kind);
    }

    /**
     * The same buckets, applied to any query over this table.
     *
     * A static taking a plain builder rather than only the scope above, because
     * the asset library reaches this table through `whereHas('file', …)` — and
     * the callback a `whereHas` hands out is a builder over the relation, not
     * over `$this`, so a scope call inside one does not type-check. Sharing the
     * conditions here is what keeps the library's "Images" and the file
     * browser's "Images" from quietly meaning different things.
     *
     * Generic in the builder's model because `Builder` is invariant in it: the
     * scope above holds a `Builder<$this>` and `whereHas` hands out a builder
     * over whatever the relation points at, and a single concrete parameter type
     * would reject one of the two callers.
     *
     * @template TQuery of Model
     *
     * @param  Builder<TQuery>  $query
     */
    public static function constrainToKind(Builder $query, string $kind): void
    {
        match ($kind) {
            'image', 'video', 'audio' => $query->where('mime_type', 'like', $kind.'/%'),
            'pdf' => $query->where('mime_type', 'application/pdf'),
            // Everything the named buckets do not claim.
            'file' => $query
                ->where('mime_type', 'not like', 'image/%')
                ->where('mime_type', 'not like', 'video/%')
                ->where('mime_type', 'not like', 'audio/%')
                ->where('mime_type', '!=', 'application/pdf'),
            default => null,
        };
    }

    // -- State ---------------------------------------------------------------

    /**
     * The bucket the client renders this file as.
     *
     * Derived from the MIME type rather than the extension, and kept here rather
     * than in the client so the browser's filter and the message's rendering
     * cannot disagree about what counts as an image.
     *
     * @return 'image'|'video'|'audio'|'pdf'|'file'
     */
    public function kind(): string
    {
        return match (true) {
            $this->isImage() => 'image',
            $this->isVideo() => 'video',
            $this->isAudio() => 'audio',
            $this->isPdf() => 'pdf',
            default => 'file',
        };
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function hasThumbnail(): bool
    {
        return $this->thumbnail_path !== null;
    }

    /**
     * Human-readable size for the attachment chip.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
