<?php

namespace Database\Factories;

use App\Models\BiteMemory;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiteMemory>
 */
class BiteMemoryFactory extends Factory
{
    protected $model = BiteMemory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => null,
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'is_enabled' => true,
            'position' => BiteMemory::POSITION_GAP,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(['workspace_id' => $workspace->id]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    public function saying(string $title, string $body): static
    {
        return $this->state(['title' => $title, 'body' => $body]);
    }
}
