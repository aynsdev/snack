<?php

use App\Enums\ConnectorKind;
use App\Models\OAuthToken;
use App\Services\Outbound\AgentAttestation;
use Illuminate\Testing\TestResponse;

/**
 * `agent_attestation`: how a LocalAgent proves to the dispatcher whose agent it
 * is, without the dispatcher ever holding the connector token.
 */
beforeEach(function () {
    enableAgentDispatcher();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
});

function requestAttestation(object $test, string $token, mixed $nonce): TestResponse
{
    return $test->withToken($token)
        ->postJson('/mcp', mcpCall('tools/call', [
            'name' => 'agent_attestation',
            'arguments' => ['nonce' => $nonce],
        ]))
        ->assertOk();
}

/**
 * @return array<string, mixed>
 */
function decodeAttestation(string $attestation): array
{
    [$payload] = explode('.', $attestation, 2);

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

it('attests to the agent connector over the real endpoint', function () {
    [, $token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();
    $connector = OAuthToken::query()->sole();
    $nonce = 'n0nce_from-the-dispatcher-1234';

    $response = requestAttestation($this, $token, $nonce);

    expect($response->json('result.isError'))->toBeFalse();

    $attestation = $response->json('result.content.0.text');

    expect($attestation)->toMatch('/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/');

    [$payload, $signature] = explode('.', $attestation);

    expect(hash_equals(hash_hmac('sha256', 'v1:attest:'.$payload, dispatcherSecret()), $signature))->toBeTrue()
        ->and(hash_equals(hash_hmac('sha256', 'v1:attest:'.$payload, 'some-other-secret'), $signature))->toBeFalse();

    $claims = decodeAttestation($attestation);

    expect(array_keys($claims))->toBe([
        'typ', 'v', 'instance', 'workspace_id', 'workspace_slug', 'user_id',
        'username', 'connector_id', 'nonce', 'iat', 'exp',
    ])
        ->and($claims['typ'])->toBe('localagent.attestation')
        ->and($claims['v'])->toBe(1)
        ->and($claims['instance'])->toBe(config('app.url'))
        ->and($claims['workspace_id'])->toBe($this->workspace->id)
        ->and($claims['workspace_slug'])->toBe($this->workspace->slug)
        ->and($claims['user_id'])->toBe($this->dev->id)
        ->and($claims['username'])->toBe($this->dev->username)
        ->and($claims['connector_id'])->toBe($connector->id)
        ->and($claims['nonce'])->toBe($nonce)
        ->and($claims['iat'])->toBe(now()->getTimestamp())
        ->and($claims['exp'])->toBe($claims['iat'] + AgentAttestation::TTL_SECONDS);
});

it('refuses an ordinary connector', function () {
    [, $token] = connectAs($this->dev, $this->workspace);
    auth()->forgetGuards();

    $response = requestAttestation($this, $token, str_repeat('a', 32));

    // Not even offered to an ordinary connector — see shouldRegister().
    expect($response->json('error.message'))->toContain('Tool [agent_attestation] not found');
});

it('refuses a nonce that is not 16 to 128 safe characters', function (mixed $nonce) {
    [, $token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();

    $response = requestAttestation($this, $token, $nonce);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('nonce');
})->with([
    'too short' => [str_repeat('a', 15)],
    'too long' => [str_repeat('a', 129)],
    'a dot' => ['abcdefghijklmnop.q'],
    'a space' => ['abcdefgh ijklmnopq'],
    'not a string' => [12345678901234567],
]);

it('accepts nonces at both length limits', function (int $length) {
    [, $token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();

    $response = requestAttestation($this, $token, str_repeat('Z', $length));

    expect($response->json('result.isError'))->toBeFalse();
})->with([16, 128]);

it('refuses when no dispatcher secret is configured', function () {
    config(['snack.agents.dispatcher_secret' => null]);
    [, $token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();

    $response = requestAttestation($this, $token, str_repeat('a', 32));

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('no agent dispatcher configured');
});

it('is listed as a tool for an agent connector', function () {
    [, $token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();

    $this->withToken($token)
        ->postJson('/mcp', mcpCall('tools/list', ['per_page' => 50]))
        ->assertOk()
        ->assertSee('agent_attestation');
});
