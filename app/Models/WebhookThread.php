<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook caller's `thread_key`, and the message that started its thread.
 *
 * See the table migration for why this exists and why it is scoped per hook.
 *
 * @property int $id
 * @property int $incoming_webhook_id
 * @property string $key
 * @property int $message_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['incoming_webhook_id', 'key', 'message_id'])]
class WebhookThread extends Model
{
    /** @use HasFactory<WebhookThreadFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<IncomingWebhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(IncomingWebhook::class, 'incoming_webhook_id');
    }

    /**
     * The thread's root. Null once that message has been soft-deleted.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
