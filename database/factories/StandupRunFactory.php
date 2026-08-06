<?php

namespace Database\Factories;

use App\Enums\StandupRunState;
use App\Models\Standup;
use App\Models\StandupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandupRun>
 */
class StandupRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'standup_id' => Standup::factory(),
            'scheduled_for' => now()->toDateString(),
            'state' => StandupRunState::Collecting,
            // Asked, because that is the state every sweep the tests exercise
            // acts on — an un-asked run is a half-second of the ask itself.
            'asked_at' => now(),
            'reminded_at' => null,
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'state' => StandupRunState::Completed,
            'closed_at' => now(),
        ]);
    }

    public function askedAt(string $when): static
    {
        return $this->state(fn (): array => ['asked_at' => $when]);
    }
}
