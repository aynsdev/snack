<?php

namespace Database\Factories;

use App\Enums\StandupResponseState;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandupResponse>
 */
class StandupResponseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'standup_run_id' => StandupRun::factory(),
            'user_id' => User::factory(),
            'state' => StandupResponseState::Pending,
            'conversation_id' => null,
            'prompt_message_id' => null,
            'answers' => [],
            'submitted_at' => null,
        ];
    }

    /**
     * @param  array<int, array{question: string, answer: string}>|null  $answers
     */
    public function submitted(?array $answers = null): static
    {
        return $this->state(fn (): array => [
            'state' => StandupResponseState::Submitted,
            'answers' => $answers ?? [
                ['question' => 'What are you working on today?', 'answer' => 'The parser.'],
            ],
            'submitted_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (): array => [
            'state' => StandupResponseState::Skipped,
            'answers' => [],
            'submitted_at' => now(),
        ]);
    }
}
