<?php

namespace Database\Factories;

use App\Enums\ColumnCategory;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Support\Positions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardColumn>
 */
class BoardColumnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->word(),
            'category' => ColumnCategory::Unstarted,
            'position' => Positions::GAP,
            'wip_limit' => null,
        ];
    }

    public function started(): static
    {
        return $this->state(fn (): array => ['category' => ColumnCategory::Started]);
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['category' => ColumnCategory::Done]);
    }
}
