<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceInvitation>
 *
 * Only the hash of the token is persisted, so the plaintext is handed back on
 * the model's transient `$plainToken` property — the same channel the
 * InviteWorkspaceMember action uses. Tests can then follow the invitation link
 * exactly as a recipient would.
 */
class WorkspaceInvitationFactory extends Factory
{
    /**
     * Plaintext tokens minted by this factory, keyed by their hash.
     *
     * Static because Laravel clones the factory for every `state()` call, so
     * the instance that ran `definition()` is not the one whose after-callbacks
     * later fire — an instance property would always read back empty.
     *
     * @var array<string, string>
     */
    private static array $plainTokens = [];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainToken = WorkspaceInvitation::generateToken();
        $hash = WorkspaceInvitation::hashToken($plainToken);

        self::$plainTokens[$hash] = $plainToken;

        return [
            'workspace_id' => Workspace::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token' => $hash,
            'expires_at' => now()->addDays((int) config('snack.invitations.ttl_days', 7)),
        ];
    }

    public function configure(): static
    {
        $attachPlainToken = function (WorkspaceInvitation $invitation): void {
            $invitation->plainToken = self::$plainTokens[$invitation->token] ?? null;
        };

        return $this->afterMaking($attachPlainToken)->afterCreating($attachPlainToken);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()]);
    }

    public function role(WorkspaceRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => $email]);
    }
}
