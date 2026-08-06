<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use App\Enums\StandupResponseState;
use App\Enums\StandupRunState;
use Carbon\CarbonImmutable;
use Database\Factories\StandupRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's asking of a standup.
 *
 * @property int $id
 * @property int $standup_id
 * @property CarbonImmutable $scheduled_for
 * @property StandupRunState $state
 * @property CarbonImmutable|null $asked_at
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $summary_message_id
 * @property int|null $responses_count Aggregate, present only where the query
 *                                     asked for it — how many people this run went out to.
 * @property int|null $answered_count Aggregate, as above — how many of them
 *                                    have replied.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Standup $standup
 */
#[Fillable([
    'standup_id', 'scheduled_for', 'state', 'asked_at',
    'reminded_at', 'closed_at', 'summary_message_id',
])]
class StandupRun extends Model
{
    /** @use HasFactory<StandupRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => StandupRunState::class,
            'scheduled_for' => 'date',
            'asked_at' => 'datetime',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Standup, $this>
     */
    public function standup(): BelongsTo
    {
        return $this->belongsTo(Standup::class);
    }

    /**
     * @return HasMany<StandupResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(StandupResponse::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function summaryMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'summary_message_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    // -- State ---------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * When this run stops taking answers, in real time.
     *
     * Measured from when it was actually asked rather than from the time it was
     * scheduled for: a run that went out late because the queue was backed up
     * still owes its members the full window to answer in, and closing on the
     * schedule would give them whatever was left of it.
     */
    public function closesAt(): CarbonImmutable
    {
        $from = $this->asked_at ?? $this->created_at ?? CarbonImmutable::now();

        return $from->addMinutes($this->standup->closes_after_minutes);
    }

    /**
     * When the people who have not answered should be nudged, or null if this
     * standup does not chase.
     */
    public function remindsAt(): ?CarbonImmutable
    {
        $after = $this->standup->reminder_after_minutes;

        if ($after === null) {
            return null;
        }

        $from = $this->asked_at ?? $this->created_at ?? CarbonImmutable::now();

        return $from->addMinutes($after);
    }

    /**
     * Whether everybody who was asked has now dealt with it — which is what
     * lets a standup be written up at 09:34 instead of at lunchtime.
     */
    public function isFullyAnswered(): bool
    {
        return ! $this->responses()
            ->where('state', StandupResponseState::Pending)
            ->exists();
    }

    /**
     * How the run is titled in the channel: "Monday 10 August".
     */
    public function dateLabel(): string
    {
        return $this->scheduled_for->translatedFormat('l j F');
    }
}
