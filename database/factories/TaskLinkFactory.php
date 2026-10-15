<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLink>
 */
class TaskLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'url' => 'https://github.com/example/app/pull/'.fake()->unique()->numberBetween(1, 99999),
            'title' => fake()->sentence(4),
        ];
    }
}
