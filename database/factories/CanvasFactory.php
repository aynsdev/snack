<?php

namespace Database\Factories;

use App\Models\Canvas;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Canvas>
 */
class CanvasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'updated_by' => fn (array $attributes): mixed => $attributes['created_by'],
            'title' => Str::title(fake()->words(4, true)),
            'body' => fake()->paragraphs(3, true),
            'content' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace, ?User $creator = null): static
    {
        $creator ??= $workspace->owner;

        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
