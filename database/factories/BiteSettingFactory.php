<?php

namespace Database\Factories;

use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use App\Models\BiteSetting;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiteSetting>
 */
class BiteSettingFactory extends Factory
{
    protected $model = BiteSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'is_enabled' => true,
            'audience' => BiteAudience::Everyone,
            'channel_mode' => BiteChannelMode::All,
            'channel_ids' => [],
            'instructions' => null,
            'context_messages' => null,
            'max_steps' => null,
            // Null is "every capability" — see the migration for why the stored
            // form is an allow-list.
            'capabilities' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(['workspace_id' => $workspace->id]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    /**
     * @param  array<int, int>  $channelIds
     */
    public function onlyIn(array $channelIds): static
    {
        return $this->state([
            'channel_mode' => BiteChannelMode::Allow,
            'channel_ids' => $channelIds,
        ]);
    }

    /**
     * @param  array<int, int>  $channelIds
     */
    public function except(array $channelIds): static
    {
        return $this->state([
            'channel_mode' => BiteChannelMode::Deny,
            'channel_ids' => $channelIds,
        ]);
    }

    /**
     * @param  array<int, BiteCapability>  $capabilities
     */
    public function allowing(array $capabilities): static
    {
        return $this->state([
            'capabilities' => array_map(
                fn (BiteCapability $capability): string => $capability->value,
                $capabilities,
            ),
        ]);
    }
}
