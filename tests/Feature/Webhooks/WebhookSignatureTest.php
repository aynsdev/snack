<?php

use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Services\WebhookSignature;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->webhook = IncomingWebhook::factory()
        ->forChannel($this->channel)
        ->signed()
        ->create();

    $this->token = $this->webhook->token;
    $this->secret = $this->webhook->signing_secret;
});

/**
 * Post a body with signature headers, exactly as a caller would.
 *
 * The body is built as a string first and both signed and sent as those bytes —
 * which is the whole discipline the scheme demands. Handing an array to the test
 * client would have it re-encode after signing, and the test would be asserting
 * against a payload nobody sent.
 *
 * @param  array<string, string>  $headers  Overrides, for the failure cases.
 */
function signedPost(string $token, string $body, ?string $secret, array $headers = []): TestResponse
{
    $timestamp = (string) now()->getTimestamp();

    $signed = $secret === null
        ? []
        : [
            WebhookSignature::TIMESTAMP_HEADER => $timestamp,
            WebhookSignature::SIGNATURE_HEADER => app(WebhookSignature::class)
                ->sign($secret, $timestamp, $body),
        ];

    return test()->call(
        method: 'POST',
        uri: "/hooks/{$token}",
        server: test()->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            ...$signed,
            ...$headers,
        ]),
        content: $body,
    );
}

it('accepts a correctly signed delivery', function () {
    signedPost($this->token, '{"text":"signed and delivered"}', $this->secret)
        ->assertCreated();

    expect(Message::query()->firstOrFail()->body)->toBe('signed and delivered');
});

it('refuses a delivery with no signature at all', function () {
    // The point of the feature: holding the URL is no longer enough.
    $this->postJson("/hooks/{$this->token}", ['text' => 'no signature'])
        ->assertStatus(401)
        ->assertJson(['ok' => false]);

    expect(Message::query()->count())->toBe(0);
});

it('refuses a signature made with the wrong secret', function () {
    signedPost($this->token, '{"text":"hello"}', WebhookSignature::generateSecret())
        ->assertStatus(401);

    expect(Message::query()->count())->toBe(0);
});

it('refuses a signature that does not cover the body that arrived', function () {
    $timestamp = (string) now()->getTimestamp();

    // Signed one payload, sent another — which is exactly what a tampering
    // proxy does, and what re-encoding JSON after signing does by accident.
    $signature = app(WebhookSignature::class)
        ->sign($this->secret, $timestamp, '{"text":"the harmless one"}');

    $this->call(
        method: 'POST',
        uri: "/hooks/{$this->token}",
        server: $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            WebhookSignature::TIMESTAMP_HEADER => $timestamp,
            WebhookSignature::SIGNATURE_HEADER => $signature,
        ]),
        content: '{"text":"the replaced one"}',
    )->assertStatus(401);

    expect(Message::query()->count())->toBe(0);
});

it('refuses a replayed request once the window has passed', function () {
    $body = '{"text":"captured from a log"}';
    $timestamp = (string) now()->getTimestamp();

    $signature = app(WebhookSignature::class)->sign($this->secret, $timestamp, $body);

    $replay = fn () => $this->call(
        method: 'POST',
        uri: "/hooks/{$this->token}",
        server: $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            WebhookSignature::TIMESTAMP_HEADER => $timestamp,
            WebhookSignature::SIGNATURE_HEADER => $signature,
        ]),
        content: $body,
    );

    $replay()->assertCreated();

    // The same bytes and the same valid signature, later. Without the timestamp
    // inside the signed string this would post forever.
    $this->travel(config('snack.webhooks.signature_tolerance_seconds') + 60)->seconds();

    $replay()->assertStatus(401);

    expect(Message::query()->count())->toBe(1);
});

it('refuses a fresh timestamp bolted onto an old signature', function () {
    $body = '{"text":"hello"}';

    $old = (string) now()->subHour()->getTimestamp();
    $signature = app(WebhookSignature::class)->sign($this->secret, $old, $body);

    // Changing the timestamp changes what must have been signed — which is the
    // entire reason it lives inside the HMAC rather than beside it.
    $this->call(
        method: 'POST',
        uri: "/hooks/{$this->token}",
        server: $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            WebhookSignature::TIMESTAMP_HEADER => (string) now()->getTimestamp(),
            WebhookSignature::SIGNATURE_HEADER => $signature,
        ]),
        content: $body,
    )->assertStatus(401);

    expect(Message::query()->count())->toBe(0);
});

it('still accepts unsigned deliveries on a hook with no secret', function () {
    $plain = IncomingWebhook::factory()->forChannel($this->channel)->create();

    // Signing is opt-in. Every hook made before the feature existed, and every
    // three-line curl call, carries on working.
    $this->postJson("/hooks/{$plain->token}", ['text' => 'unsigned is fine here'])
        ->assertCreated();
});

it('ignores signature headers on a hook that does not require them', function () {
    $plain = IncomingWebhook::factory()->forChannel($this->channel)->create();

    signedPost($plain->token, '{"text":"over-signed"}', WebhookSignature::generateSecret())
        ->assertCreated();
});

it('checks the signature before saying anything about the channel', function () {
    $this->channel->update(['is_archived' => true]);

    // An unsigned caller is not entitled to learn the hook's state — it gets the
    // 401, not the 410 that would confirm the channel was archived.
    $this->postJson("/hooks/{$this->token}", ['text' => 'hello'])
        ->assertStatus(401);
});

it('tells the caller which of the three things went wrong', function () {
    $this->postJson("/hooks/{$this->token}", ['text' => 'x'])
        ->assertStatus(401)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'requires a signature'));

    signedPost($this->token, '{"text":"x"}', WebhookSignature::generateSecret())
        ->assertStatus(401)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'did not match'));
});
