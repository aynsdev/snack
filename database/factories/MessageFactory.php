<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MessageBody;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * A message must belong to exactly one container, which the database
     * enforces. The default state produces a channel message; call
     * `inConversation()` for the other shape.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence(fake()->numberBetween(3, 20));

        return [
            'workspace_id' => Workspace::factory(),
            'sender_id' => User::factory(),
            'channel_id' => Channel::factory(),
            'conversation_id' => null,
            'parent_message_id' => null,
            // `body` is the author's source text, not HTML — see MessageBody.
            'body' => $text,
            'body_plaintext' => $text,
            'type' => MessageType::Text,
            'metadata' => [],
        ];
    }

    public function inChannel(Channel $channel): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $channel->workspace_id,
            'channel_id' => $channel->id,
            'conversation_id' => null,
        ]);
    }

    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $conversation->workspace_id,
            'channel_id' => null,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function from(User $sender): static
    {
        return $this->state(fn (): array => ['sender_id' => $sender->id]);
    }

    /**
     * A reply inside the parent's thread, inheriting the parent's container.
     */
    public function replyTo(Message $parent): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $parent->workspace_id,
            'channel_id' => $parent->channel_id,
            'conversation_id' => $parent->conversation_id,
            'parent_message_id' => $parent->id,
        ]);
    }

    public function edited(): static
    {
        return $this->state(fn (): array => ['edited_at' => now()]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }

    public function system(string $text): static
    {
        return $this->state(fn (): array => [
            'type' => MessageType::System,
            'sender_id' => null,
            'body' => $text,
            'body_plaintext' => $text,
        ]);
    }

    public function withBody(string $text): static
    {
        return $this->state(fn (): array => MessageBody::fromInput($text)->toAttributes());
    }
}
