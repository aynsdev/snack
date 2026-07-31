<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\ThreadSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadSubscription>
 */
class ThreadSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'root_message_id' => Message::factory(),
            'last_read_reply_id' => null,
        ];
    }

    /**
     * Not named `for()`: Factory already defines that for relationships.
     */
    public function subscribing(User $user, Message $root): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'root_message_id' => $root->id,
        ]);
    }

    /**
     * Caught up as of the given reply.
     */
    public function readThrough(Message $reply): static
    {
        return $this->state(fn (): array => [
            'last_read_reply_id' => $reply->id,
            'last_read_at' => now(),
        ]);
    }
}
