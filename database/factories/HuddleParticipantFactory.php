<?php

namespace Database\Factories;

use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HuddleParticipant>
 */
class HuddleParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'huddle_id' => Huddle::factory(),
            'user_id' => User::factory(),
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    /**
     * A finished visit — someone who was in the huddle and has since hung up.
     */
    public function left(): static
    {
        return $this->state(fn (): array => ['left_at' => now()]);
    }
}
