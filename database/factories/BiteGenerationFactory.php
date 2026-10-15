<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Models\BiteGeneration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiteGeneration>
 */
class BiteGenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'kind' => MediaKind::Image,
            'model' => MagnificModel::Mystic,
            'prompt' => fake()->sentence(),
            'aspect_ratio' => '1:1',
            'status' => GenerationStatus::Pending,
        ];
    }

    public function video(MagnificModel $model = MagnificModel::Kling26Pro): static
    {
        return $this->state(fn (): array => [
            'kind' => MediaKind::Video,
            'model' => $model,
            'aspect_ratio' => '16:9',
            'duration' => 5,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
