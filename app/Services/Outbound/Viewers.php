<?php

namespace App\Services\Outbound;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who can see a project's agent channel, worked out once per person.
 *
 * Shared between a change's "before" and its "after" when the change cannot
 * alter the answer — a label rename, say — so two hundred cards with one
 * assignee cost one lookup rather than four hundred. A change that can alter
 * it keeps the people and forgets the answers.
 */
final class Viewers
{
    /** @var array<int, User|null> */
    private array $users = [];

    /** @var array<int, bool> */
    private array $answers = [];

    public function __construct(private ?int $channelId) {}

    public function forgettingAnswers(): self
    {
        $copy = clone $this;
        $copy->answers = [];

        return $copy;
    }

    /**
     * By the same policy that decides it on screen.
     */
    public function canView(int $userId, Channel $channel): bool
    {
        if ($channel->id !== $this->channelId) {
            $this->channelId = $channel->id;
            $this->answers = [];
        }

        if (! array_key_exists($userId, $this->answers)) {
            $user = array_key_exists($userId, $this->users)
                ? $this->users[$userId]
                : $this->users[$userId] = User::query()->find($userId);

            $this->answers[$userId] = $user !== null && Gate::forUser($user)->allows('view', $channel);
        }

        return $this->answers[$userId];
    }
}
