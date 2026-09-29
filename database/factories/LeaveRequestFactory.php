<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (array $attributes): int => (int) Employee::query()->whereKey($attributes['employee_id'])->value('workspace_id'),
            'employee_id' => Employee::factory(),
            'leave_type_id' => fn (array $attributes): int => LeaveType::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-06',
            'days' => 2,
            'status' => LeaveStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => LeaveStatus::Approved, 'reviewed_at' => now()]);
    }
}
