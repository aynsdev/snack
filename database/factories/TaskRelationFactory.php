<?php

namespace Database\Factories;

use App\Enums\TaskRelationType;
use App\Models\Task;
use App\Models\TaskRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskRelation>
 */
class TaskRelationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'related_task_id' => Task::factory(),
            'type' => TaskRelationType::RelatesTo,
        ];
    }
}
