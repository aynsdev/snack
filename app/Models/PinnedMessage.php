<?php

namespace App\Models;

use Database\Factories\PinnedMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message pinned to the top of a channel or conversation.
 *
 * Unlike {@see SavedMessage}, a pin is shared by everyone in the container, so
 * the container is denormalized onto the row and a message can be pinned once.
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $channel_id
 * @property int|null $conversation_id
 * @property int $pinned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 */
#[Fillable(['message_id', 'channel_id', 'conversation_id', 'pinned_by'])]
class PinnedMessage extends Model
{
    /** @use HasFactory<PinnedMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
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
     * @return BelongsTo<User, $this>
     */
    public function pinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
