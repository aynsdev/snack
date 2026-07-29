<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            // Suffixed rather than relying on SlugGenerator: factories run in
            // tight loops and a random suffix avoids a lookup per row.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'member_limit' => 500,
            'settings' => [
                'channels' => ['allow_self_serve_joins' => true],
                'auth' => ['allow_invitations' => true],
            ],
        ];
    }

    /**
     * A workspace where members must be added to channels by an admin.
     */
    public function withoutSelfServeJoins(): static
    {
        return $this->state(fn (array $attributes): array => [
            'settings' => [
                ...$attributes['settings'],
                'channels' => ['allow_self_serve_joins' => false],
            ],
        ]);
    }

    public function withMemberLimit(int $limit): static
    {
        return $this->state(fn (): array => ['member_limit' => $limit]);
    }
}
