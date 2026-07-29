<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Two `word()` calls rather than `words()`, which Faker types as
        // array|string in both of its forms. Only the slug is unique-
        // constrained, and that gets a random suffix below.
        $name = fake()->word().'-'.fake()->word();

        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'topic' => null,
            'type' => ChannelType::Public,
            'is_archived' => false,
            'is_default' => false,
            'settings' => [],
        ];
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['type' => ChannelType::Private]);
    }

    public function announcement(): static
    {
        return $this->state(fn (): array => ['type' => ChannelType::Announcement]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_archived' => true]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * Give the channel a stable, readable name instead of random words.
     */
    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }
}
