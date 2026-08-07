<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomingWebhook>
 */
class IncomingWebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * The plaintext is not stashed on the factory for the test to read
         * back. It cannot be: `create($overrides)` builds a *clone* and runs
         * `definition()` on that, so a property set here would be set on an
         * object the caller never sees — a trap that costs an afternoon before
         * anybody suspects the factory.
         *
         * There is no need for one anyway. `token` is an encrypted column, so
         * `$webhook->token` decrypts to exactly the string that goes in the URL.
         */
        $token = IncomingWebhook::generateToken();

        return [
            'workspace_id' => Workspace::factory(),
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'name' => 'Deploy notifications',
            'default_username' => null,
            'default_icon' => null,
            'token_hash' => IncomingWebhook::hashToken($token),
            'token' => $token,
        ];
    }

    /**
     * Point the hook at a channel, taking the workspace from it.
     *
     * The two must agree — a delivery stamps the message with the hook's
     * workspace and posts it into the hook's channel — and letting a test set
     * them separately is how a fixture ends up describing a state the
     * application cannot produce.
     */
    public function forChannel(Channel $channel): static
    {
        return $this->state(fn (): array => [
            'channel_id' => $channel->id,
            'workspace_id' => $channel->workspace_id,
        ]);
    }

    /**
     * A hook whose token the test chose, for one that asserts on the URL itself.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn (): array => [
            'token_hash' => IncomingWebhook::hashToken($plainToken),
            'token' => $plainToken,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
