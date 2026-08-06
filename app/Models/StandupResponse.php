<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use App\Enums\StandupResponseState;
use App\Policies\StandupPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\StandupResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answers to one run.
 *
 * `answers` carries the question text alongside each answer — see the migration
 * for why the wording is snapshotted rather than joined to.
 *
 * @property int $id
 * @property int $standup_run_id
 * @property int $user_id
 * @property StandupResponseState $state
 * @property int|null $conversation_id
 * @property int|null $prompt_message_id
 * @property array<int, array{question: string, answer: string}> $answers
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StandupRun $run
 * @property-read User $user
 */
#[Fillable([
    'standup_run_id', 'user_id', 'state', 'conversation_id',
    'prompt_message_id', 'answers', 'submitted_at',
])]
/*
 * Pointed at the standup's own policy rather than getting one of its own.
 *
 * Discovery would look for a StandupResponsePolicy, and that file would hold a
 * single method answering "is this row yours" — which is one half of a question
 * whose other half ("who may configure the standup at all") lives next door.
 * Splitting them across two files is how the two rules drift apart.
 */
#[UsePolicy(StandupPolicy::class)]
class StandupResponse extends Model
{
    /** @use HasFactory<StandupResponseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => StandupResponseState::class,
            'answers' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<StandupRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(StandupRun::class, 'standup_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The direct message the bot asked with.
     *
     * @return BelongsTo<Message, $this>
     */
    public function promptMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'prompt_message_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('state', StandupResponseState::Pending);
    }

    // -- State ---------------------------------------------------------------

    public function isOutstanding(): bool
    {
        return $this->state->isOutstanding();
    }

    /**
     * The answers as question/answer pairs, with anything empty dropped.
     *
     * Blank answers are removed here rather than refused on the way in: a
     * standup with three questions and one thing to say should not be a form you
     * cannot submit, and an empty bullet in the channel summary is noise.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function answerPairs(): array
    {
        return collect($this->answers)
            ->map(fn (array $pair): array => [
                'question' => trim($pair['question']),
                'answer' => trim($pair['answer']),
            ])
            ->filter(fn (array $pair): bool => $pair['answer'] !== '')
            ->values()
            ->all();
    }
}
