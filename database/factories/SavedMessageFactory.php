<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedMessage>
 */
class SavedMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message_id' => Message::factory(),
            'notes' => null,
        ];
    }

    public function withReminder(): static
    {
        return $this->state(fn (): array => ['reminder_at' => now()->addDay()]);
    }
}
