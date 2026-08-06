<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Standup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Standup>
 */
class StandupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'name' => 'Daily standup',
            'questions' => Standup::DEFAULT_QUESTIONS,
            'days_of_week' => Standup::DEFAULT_DAYS,
            'ask_at' => '09:30',
            // UTC by default so a test that does not care about timezones is
            // not quietly asserting against the machine's.
            'timezone' => 'UTC',
            'reminder_after_minutes' => 90,
            'closes_after_minutes' => 240,
            'is_active' => true,
        ];
    }

    /**
     * Attach the standup to a channel, taking the workspace from it.
     *
     * The two must agree — a standup DMs its channel's members and posts back
     * to it — and letting a test set them separately is how a fixture ends up
     * describing a state the application cannot produce.
     */
    public function forChannel(Channel $channel): static
    {
        return $this->state(fn (): array => [
            'channel_id' => $channel->id,
            'workspace_id' => $channel->workspace_id,
        ]);
    }

    /**
     * Runs every day, which spares a test that only cares about the time from
     * having to know what weekday it is being run on.
     */
    public function daily(): static
    {
        return $this->state(fn (): array => ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);
    }

    public function at(string $time, string $timezone = 'UTC'): static
    {
        return $this->state(fn (): array => ['ask_at' => $time, 'timezone' => $timezone]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Never chases. The default has a reminder, so this is how a test asserts
     * that nothing is sent rather than that something is sent late.
     */
    public function withoutReminder(): static
    {
        return $this->state(fn (): array => ['reminder_after_minutes' => null]);
    }
}
