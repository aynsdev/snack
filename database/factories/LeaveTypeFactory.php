<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->word(),
            'color' => 'sky',
            'days_per_year' => 15,
            'is_paid' => true,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn (): array => ['days_per_year' => null]);
    }
}
