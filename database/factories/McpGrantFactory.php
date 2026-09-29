<?php

namespace Database\Factories;

use App\Models\McpGrant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passport\ClientRepository;

/**
 * @extends Factory<McpGrant>
 */
class McpGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'client_id' => fn (): string => app(ClientRepository::class)
                ->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false)
                ->getKey(),
        ];
    }
}
