<?php

namespace Database\Factories;

use App\Enums\MentionType;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mention>
 */
class MentionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'message_id' => Message::factory(),
            'mentioned_user_id' => User::factory(),
            'mention_type' => MentionType::User,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function type(MentionType $type): static
    {
        return $this->state(fn (): array => ['mention_type' => $type]);
    }
}
