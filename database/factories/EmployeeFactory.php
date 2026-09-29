<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'job_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Engineering', 'Design', 'Operations', 'Sales']),
            'employment_type' => EmploymentType::FullTime,
            'status' => EmploymentStatus::Active,
            'hired_on' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
        ];
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => [
            'status' => EmploymentStatus::Terminated,
            'terminated_on' => now()->subDay()->toDateString(),
        ]);
    }
}
