<?php

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            // Random suffix rather than a SlugGenerator lookup: factories run in
            // tight loops, and `username` is citext-unique.
            'username' => Str::slug($name, '.').'.'.Str::lower(Str::random(5)),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'title' => fake()->jobTitle(),
            'department' => fake()->randomElement([
                'Engineering', 'Operations', 'Finance', 'Marketing', 'Support',
            ]),
            'timezone' => fake()->randomElement([
                'UTC', 'America/New_York', 'Europe/London', 'Asia/Manila', 'Australia/Sydney',
            ]),
            'presence_status' => PresenceStatus::Offline,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function inDepartment(string $department): static
    {
        return $this->state(fn (): array => ['department' => $department]);
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'presence_status' => PresenceStatus::Online,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * A user with a predictable identity, for seeded demo data and tests that
     * assert on a specific handle.
     */
    public function named(string $name, string $username, string $email): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'username' => $username,
            'email' => $email,
        ]);
    }
}
