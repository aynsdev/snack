<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'type' => ConversationType::Direct,
            'name' => null,
            // Overwritten by `between()`; random by default so unrelated
            // factory rows never collide on the direct-conversation unique index.
            'participants_hash' => hash('sha256', Str::random(32)),
        ];
    }

    public function group(?string $name = null): static
    {
        return $this->state(fn (): array => [
            'type' => ConversationType::Group,
            'name' => $name,
        ]);
    }

    /**
     * Build a conversation with a real participant set, including the
     * participant rows and the hash that enforces direct-message uniqueness.
     *
     * @param  array<int, User>  $users
     */
    public function between(array $users): static
    {
        $ids = array_map(fn (User $user): int => $user->id, $users);

        return $this
            ->state(fn (): array => [
                'type' => count($ids) > 2 ? ConversationType::Group : ConversationType::Direct,
                'participants_hash' => Conversation::hashParticipants($ids),
                'created_by' => $ids[0] ?? null,
            ])
            ->afterCreating(function (Conversation $conversation) use ($ids): void {
                foreach ($ids as $id) {
                    ConversationParticipant::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $id,
                        'joined_at' => now(),
                    ]);
                }
            });
    }
}
