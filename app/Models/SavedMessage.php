<?php

namespace App\Models;

use Database\Factories\SavedMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A private bookmark on a message, visible only to the user who saved it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $message_id
 * @property string|null $notes
 * @property Carbon|null $reminder_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 */
#[Fillable(['user_id', 'message_id', 'notes', 'reminder_at', 'reminded_at'])]
class SavedMessage extends Model
{
    /** @use HasFactory<SavedMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reminder_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
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
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Reminders that have come due but have not been delivered yet.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNotNull('reminder_at')
            ->whereNull('reminded_at')
            ->where('reminder_at', '<=', now());
    }
}
