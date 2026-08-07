<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->numerify('#.#.#'),
            'notes' => fake()->sentence(),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ];
    }

    /**
     * A release whose date has gone by — what {@see Release::isOverdue()} reads.
     */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'starts_on' => now()->subMonths(2)->toDateString(),
            'ends_on' => now()->subWeek()->toDateString(),
        ]);
    }
}
