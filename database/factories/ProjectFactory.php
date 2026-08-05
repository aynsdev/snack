<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().' '.fake()->word();

        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            // Both the key and the slug are unique per workspace, and a factory
            // that made two projects in one workspace would collide on a word.
            'key' => Str::upper(Str::random(4)),
            'description' => fake()->sentence(),
            'color' => 'violet',
            'uses_sprints' => true,
            'is_archived' => false,
            'card_fields' => [],
        ];
    }

    /**
     * A kanban project: a board and a backlog, and no sprints between them.
     */
    public function kanban(): static
    {
        return $this->state(fn (): array => ['uses_sprints' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_archived' => true]);
    }
}
