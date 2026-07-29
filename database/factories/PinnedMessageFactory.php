<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PinnedMessage>
 */
class PinnedMessageFactory extends Factory
{
    /**
     * The container is copied from the message so the "exactly one of
     * channel/conversation" CHECK constraint is always satisfied.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'pinned_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PinnedMessage $pin): void {
            if ($pin->channel_id === null && $pin->conversation_id === null) {
                $message = Message::find($pin->message_id);

                $pin->channel_id = $message?->channel_id;
                $pin->conversation_id = $message?->conversation_id;
            }
        });
    }

    public function forMessage(Message $message): static
    {
        return $this->state(fn (): array => [
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'conversation_id' => $message->conversation_id,
        ]);
    }
}
