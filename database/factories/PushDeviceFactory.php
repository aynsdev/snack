<?php

namespace Database\Factories;

use App\Enums\PushPlatform;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => fake()->unique()->regexify('[A-Za-z0-9_-]{22}:[A-Za-z0-9_-]{140}'),
            'platform' => fake()->randomElement(PushPlatform::cases()),
            'device_name' => fake()->randomElement(['Pixel 9', 'iPhone 16', 'Galaxy S25']),
            'oauth_access_token_id' => null,
            'last_registered_at' => now(),
        ];
    }

    /**
     * A token from the Expo app — the Snack mobile app's kind.
     */
    public function expo(): static
    {
        return $this->state(fn (): array => [
            'token' => 'ExponentPushToken['.fake()->unique()->regexify('[A-Za-z0-9_-]{22}').']',
        ]);
    }
}
