<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use App\Support\Positions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * A task always needs a column, and the column has to belong to the same
     * project — so the two are resolved together rather than each spawning its
     * own parent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'project_id' => $project,
            'number' => fake()->unique()->numberBetween(1, 100_000),
            'board_column_id' => fn (array $attributes): int => BoardColumn::factory()
                ->create(['project_id' => $attributes['project_id']])->id,
            'sprint_id' => null,
            'parent_task_id' => null,
            'type' => TaskType::Story,
            'priority' => TaskPriority::Medium,
            'title' => fake()->sentence(4),
            'description' => null,
            'assignee_id' => null,
            'reporter_id' => null,
            'position' => Positions::GAP,
            'story_points' => null,
            'due_on' => null,
            'completed_at' => null,
        ];
    }

    public function bug(): static
    {
        return $this->state(fn (): array => ['type' => TaskType::Bug]);
    }

    public function epic(): static
    {
        return $this->state(fn (): array => ['type' => TaskType::Epic]);
    }
}
