<?php

namespace Database\Factories;

use App\Models\AssetFolder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssetFolder>
 */
class AssetFolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            /*
             * Suffixed, because sibling names are unique per workspace and a
             * test that makes three folders in one would otherwise collide on a
             * dictionary word — a constraint violation inside RefreshDatabase's
             * transaction, which then poisons everything after it.
             */
            'name' => Str::title(fake()->word()).' '.Str::upper(Str::random(4)),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * A shelf inside another shelf.
     */
    public function inside(AssetFolder $parent): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $parent->workspace_id,
            'parent_id' => $parent->id,
        ]);
    }
}
