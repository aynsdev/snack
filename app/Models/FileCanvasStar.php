<?php

namespace App\Models;

use Database\Factories\FileCanvasStarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A private bookmark for one file or Canvas.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int|null $canvas_id
 * @property int|null $message_attachment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'user_id', 'canvas_id', 'message_attachment_id'])]
class FileCanvasStar extends Model
{
    /** @use HasFactory<FileCanvasStarFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Canvas, $this> */
    public function canvas(): BelongsTo
    {
        return $this->belongsTo(Canvas::class);
    }

    /** @return BelongsTo<MessageAttachment, $this> */
    public function messageAttachment(): BelongsTo
    {
        return $this->belongsTo(MessageAttachment::class);
    }
}
