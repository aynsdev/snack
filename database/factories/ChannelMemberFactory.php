<?php

namespace Database\Factories;

use App\Enums\ChannelRole;
use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelMember>
 */
class ChannelMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'role' => ChannelRole::Member,
            'notification_level' => NotificationLevel::Mentions,
            'is_muted' => false,
            'joined_at' => now(),
        ];
    }

    public function channelAdmin(): static
    {
        return $this->state(fn (): array => ['role' => ChannelRole::Admin]);
    }

    public function muted(): static
    {
        return $this->state(fn (): array => ['is_muted' => true]);
    }
}
