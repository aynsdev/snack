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
 */
#[Fillable([
    'workspace_id', 'message_id', 'uploaded_by', 'disk', 'path', 'thumbnail_path',
    'original_name', 'mime_type', 'size', 'metadata',
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
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Uploads that were never attached to a sent message, eligible for pruning.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrphaned(Builder $query): void
    {
        $query->whereNull('message_id');
    }

    // -- State ---------------------------------------------------------------

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
