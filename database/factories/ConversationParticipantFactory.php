<?php

namespace Database\Factories;

use App\Enums\NotificationLevel;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'notification_level' => NotificationLevel::All,
            'is_muted' => false,
            'joined_at' => now(),
        ];
    }

    public function departed(): static
    {
        return $this->state(fn (): array => ['left_at' => now()]);
    }
}
