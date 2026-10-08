<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Day shift',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'grace_minutes' => 10,
            'days' => [1, 2, 3, 4, 5],
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function overnight(): static
    {
        return $this->state(fn (): array => ['name' => 'Night shift', 'starts_at' => '22:00', 'ends_at' => '06:00']);
    }
}
