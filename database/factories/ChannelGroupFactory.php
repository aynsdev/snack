<?php

namespace Database\Factories;

use App\Models\ChannelGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Positions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelGroup>
 */
class ChannelGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            // Names are unique per person per workspace, and a factory making
            // two groups for one person would collide on a word.
            'name' => Str::title(fake()->word()).' '.Str::upper(Str::random(4)),
            'position' => Positions::GAP,
        ];
    }
}
