<?php

namespace Database\Factories;

use App\Models\ProjectGroup;
use App\Models\Workspace;
use App\Support\Positions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectGroup>
 */
class ProjectGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            // Names are unique per workspace, and a factory making two groups in
            // one workspace would collide on a word.
            'name' => Str::title(fake()->word()).' '.Str::upper(Str::random(4)),
            'position' => Positions::GAP,
        ];
    }
}
