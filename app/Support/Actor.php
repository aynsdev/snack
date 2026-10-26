<?php

namespace App\Support;

use App\Enums\ActorVia;
use App\Models\OAuthToken;
use App\Models\User;

/**
 * Who made a change, and how it reached Snack — the `actor` block of an
 * outbound event. Resolved by {@see App\Services\ActorContext}.
 */
final readonly class Actor
{
    public function __construct(
        public ActorVia $via,
        public ?int $userId = null,
        public ?string $connectorId = null,
        public ?string $connectorName = null,
        public bool $isBot = false,
    ) {}

    public static function web(User $user): self
    {
        return new self(ActorVia::Web, $user->id, isBot: $user->isBot());
    }

    public static function mcp(User $user, string $connectorId): self
    {
        return new self(ActorVia::Mcp, $user->id, $connectorId, isBot: $user->isBot());
    }

    public static function agent(User $user, OAuthToken $connector): self
    {
        return new self(ActorVia::Agent, $user->id, $connector->id, $connector->name, $user->isBot());
    }

    public static function webhook(User $bot): self
    {
        return new self(ActorVia::Webhook, $bot->id, isBot: true);
    }

    public static function system(): self
    {
        return new self(ActorVia::System);
    }

    public function isAgentFor(User $user): bool
    {
        return $this->via === ActorVia::Agent && $this->userId === $user->id;
    }

    /**
     * @return array{user_id: int|null, via: string, connector_id: string|null, is_bot: bool}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'via' => $this->via->value,
            'connector_id' => $this->connectorId,
            'is_bot' => $this->isBot,
        ];
    }
}
