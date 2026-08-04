<?php

namespace App\Models;

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.
use Carbon\CarbonImmutable;
use Database\Factories\HuddleParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's visit to one huddle.
 *
 * A row per visit rather than per person — see the migration for why rejoining
 * must not overwrite the record of having already been here.
 *
 * @property int $id
 * @property int $huddle_id
 * @property int $user_id
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Huddle $huddle
 * @property-read User $user
 */
#[Fillable(['huddle_id', 'user_id', 'joined_at', 'left_at'])]
class HuddleParticipant extends Model
{
    /** @use HasFactory<HuddleParticipantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $participant): void {
            $participant->joined_at ??= now();
        });
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Huddle, $this>
     */
    public function huddle(): BelongsTo
    {
        return $this->belongsTo(Huddle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -- State ---------------------------------------------------------------

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }
}
