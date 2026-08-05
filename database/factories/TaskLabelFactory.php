<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TaskLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLabel>
 */
class TaskLabelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Names are unique per project and citext, so a bare word collides
            // as soon as a test makes two.
            'name' => fake()->word().'-'.fake()->numberBetween(1, 9999),
            'color' => 'slate',
        ];
    }
}
