<?php

use App\Enums\ConnectorKind;
use App\Services\Outbound\OutboundEvents;
use App\Support\SharedSecret;
use Illuminate\Support\Facades\Log;

/**
 * The dispatcher refuses a weak shared secret, so Snack does too: rather than
 * sign events the dispatcher would never accept, it leaves them off and says
 * why, once.
 */
it('accepts random bytes written as base64, base64url or hex', function (string $secret) {
    expect(SharedSecret::problem($secret))->toBeNull();
})->with([
    'base64' => fn () => base64_encode(random_bytes(32)),
    'base64url' => fn () => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='),
    'hex' => fn () => bin2hex(random_bytes(32)),
    'the test fixture' => fn () => dispatcherSecret(),
]);

it('refuses a weak secret, and says why', function (string $secret, string $problem) {
    expect(SharedSecret::problem($secret))->toContain($problem);
})->with([
    'a phrase' => ['correct horse battery staple!!', 'not hex, base64'],
    'the old fixture' => ['dispatcher-secret', 'not hex, base64'],
    'too short' => [fn () => base64_encode(random_bytes(31)), 'fewer than 32 bytes'],
    'one byte repeated' => [fn () => bin2hex(str_repeat('a', 32)), 'fewer than 16 distinct'],
    'few distinct bytes' => [fn () => bin2hex(str_repeat('abcdefgh', 4)), 'fewer than 16 distinct'],
    'a repeated pattern' => [fn () => bin2hex(str_repeat('abcdefghijklmnop', 3)), 'repeats a shorter pattern'],
    'a pattern repeated in part' => [fn () => bin2hex(substr(str_repeat('abcdefghijklmnopq', 3), 0, 40)), 'repeats a shorter pattern'],
    'empty' => ['', 'not hex, base64'],
]);

it('leaves events off with a weak secret and warns once', function () {
    Log::spy();
    (fn () => self::$warnedWeakSecret = false)->call(app(OutboundEvents::class));
    $events = app(OutboundEvents::class);

    enableAgentDispatcher(secret: 'dispatcher-secret');

    expect($events->enabled())->toBeFalse()
        ->and($events->enabled())->toBeFalse();

    enableAgentDispatcher();

    expect($events->enabled())->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'SNACK_AGENT_DISPATCHER_SECRET is too weak'))->once();
});

it('issues no attestation over a weak secret', function () {
    enableAgentDispatcher(secret: 'dispatcher-secret');
    [$workspace] = makeWorkspaceWithOwner();
    $dev = joinWorkspace($workspace);
    [, $token] = connectAs($dev, $workspace, ConnectorKind::Agent);
    auth()->forgetGuards();

    $response = $this->withToken($token)
        ->postJson('/mcp', mcpCall('tools/call', ['name' => 'agent_attestation', 'arguments' => ['nonce' => 'n0nce_from-the-dispatcher-1234']]))
        ->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});
