<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use Carbon\CarbonImmutable;
use Database\Factories\BiteGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image or video somebody asked Bite to make.
 *
 * See the table migration for why this is a row of its own rather than only
 * the message the result is posted in.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int|null $request_message_id
 * @property MediaKind $kind
 * @property MagnificModel $model
 * @property string $prompt
 * @property string|null $aspect_ratio
 * @property int|null $duration
 * @property int|null $source_attachment_id
 * @property GenerationStatus $status
 * @property string|null $task_id
 * @property string|null $error
 * @property int|null $message_attachment_id
 * @property int|null $result_message_id
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 * @property-read Message|null $requestMessage
 * @property-read MessageAttachment|null $sourceAttachment
 * @property-read MessageAttachment|null $attachment
 */
#[Fillable([
    'workspace_id', 'user_id', 'request_message_id', 'kind', 'model', 'prompt', 'aspect_ratio', 'duration',
    'source_attachment_id', 'status', 'task_id', 'error', 'message_attachment_id', 'result_message_id', 'completed_at',
])]
class BiteGeneration extends Model
{
    /** @use HasFactory<BiteGenerationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'model' => MagnificModel::class,
            'status' => GenerationStatus::class,
            'duration' => 'integer',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function requestMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'request_message_id');
    }

    /**
     * @return BelongsTo<MessageAttachment, $this>
     */
    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(MessageAttachment::class, 'source_attachment_id');
    }

    /**
     * @return BelongsTo<MessageAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MessageAttachment::class, 'message_attachment_id');
    }

    /**
     * Whether the image a video starts from was sent along.
     */
    public function hasSourceImage(): bool
    {
        return $this->source_attachment_id !== null;
    }
}
