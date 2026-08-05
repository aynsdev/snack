<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Sprint;
use App\Support\Positions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Sprint '.fake()->numberBetween(1, 99),
            'goal' => fake()->sentence(),
            'starts_at' => now(),
            'ends_at' => now()->addWeeks(2),
            'started_at' => null,
            'completed_at' => null,
            'position' => Positions::GAP,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['started_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subWeeks(2),
            'completed_at' => now(),
        ]);
    }
}
