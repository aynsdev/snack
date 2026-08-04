<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Huddle>
 */
class HuddleFactory extends Factory
{
    /**
     * A live huddle with no container.
     *
     * Deliberately unusable as-is: `huddles_single_container_check` refuses a
     * row with neither a channel nor a conversation, so every test has to say
     * which container it means through `inChannel()` or `inConversation()`. A
     * default container here would let a test pass while asserting against a
     * shape the product cannot create.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * Assigned here as well as in Huddle::booted(), because `make()`
             * never runs model events — and a made huddle with no room name is
             * one the token issuer cannot mint against.
             */
            'public_id' => (string) Str::ulid(),
            'workspace_id' => Workspace::factory(),
            'started_by' => User::factory(),
            'started_at' => now(),
            'ended_at' => null,
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

    public function startedBy(User $user): static
    {
        return $this->state(fn (): array => ['started_by' => $user->id]);
    }

    /**
     * A finished huddle.
     */
    public function ended(): static
    {
        return $this->state(fn (): array => ['ended_at' => now()]);
    }

    /**
     * Put people in the huddle, with the live participant rows the roster is
     * read from.
     *
     * @param  array<int, User>  $users
     */
    public function withParticipants(array $users): static
    {
        return $this->afterCreating(function (Huddle $huddle) use ($users): void {
            foreach ($users as $user) {
                HuddleParticipant::create([
                    'huddle_id' => $huddle->id,
                    'user_id' => $user->id,
                    'joined_at' => now(),
                ]);
            }

            $huddle->unsetRelation('participants')->unsetRelation('activeParticipants');
        });
    }
}
