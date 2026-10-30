<?php

namespace App\Services\Outbound;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Actor;

/**
 * A short-lived statement, signed with the dispatcher secret, that a given
 * agent connector belongs to a given person in a given workspace.
 *
 * This is how a Kusina pairs with the agent dispatcher without the
 * dispatcher ever seeing the connector token: the agent asks Snack for an
 * attestation over the dispatcher's nonce and hands it on, and the dispatcher
 * checks the signature with the secret it already shares with Snack.
 *
 * Format: `<payload_b64url>.<sig_hex>`, where the signature is
 * `hmac_sha256(secret, "v1:attest:" . payload_b64url)`. The `v1:attest:`
 * prefix keeps an attestation from ever verifying as an outbound event
 * signature, or the other way round.
 */
class AgentAttestation
{
    public const TYPE = 'localagent.attestation';

    public const TTL_SECONDS = 300;

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    public function issue(Workspace $workspace, User $user, Actor $actor, string $nonce): string
    {
        $issuedAt = now()->getTimestamp();

        $payload = self::base64Url(json_encode([
            'typ' => self::TYPE,
            'v' => 1,
            'instance' => config('app.url'),
            'workspace_id' => $workspace->id,
            'workspace_slug' => $workspace->slug,
            'user_id' => $user->id,
            'username' => $user->username,
            'connector_id' => $actor->connectorId,
            'nonce' => $nonce,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload.'.'.self::signature($payload, (string) config('snack.agents.dispatcher_secret'));
    }

    public static function signature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', 'v1:attest:'.$payload, $secret);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
