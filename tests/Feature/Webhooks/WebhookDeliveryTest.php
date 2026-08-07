<?php

use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
});

/**
 * A hook pointed at a channel, and the plaintext token to post to it with.
 *
 * The token comes off the model rather than out of the factory: `token` is an
 * encrypted column, so reading it back decrypts to the string that goes in the
 * URL.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: IncomingWebhook, 1: string}
 */
function makeWebhook(Channel $channel, array $attributes = []): array
{
    $webhook = IncomingWebhook::factory()->forChannel($channel)->create($attributes);

    return [$webhook, $webhook->token];
}

it('posts a message into the hook\'s channel', function () {
    [$webhook, $token] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$token}", ['text' => 'Deploy #482 shipped'])
        ->assertCreated()
        ->assertJson(['ok' => true]);

    $message = Message::query()->firstOrFail();

    expect($message->body)->toBe('Deploy #482 shipped')
        ->and($message->channel_id)->toBe($this->channel->id)
        // Stamped from the hook's own columns, never from the request.
        ->and($message->workspace_id)->toBe($this->workspace->id);
});

it('posts as a bot that is a member of the channel', function () {
    [$webhook, $token] = makeWebhook($this->channel, ['name' => 'Deploy bot']);

    $this->postJson("/hooks/{$token}", ['text' => 'shipped'])->assertCreated();

    $bot = Message::query()->firstOrFail()->sender;

    expect($bot->is_bot)->toBeTrue()
        ->and($bot->name)->toBe('Deploy bot')
        // Discoverable: somebody wondering what is posting finds it in the
        // channel's own member list. See App\Services\WebhookBot.
        ->and($this->channel->fresh()->hasMember($bot))->toBeTrue();
});

it('reuses the same bot account across deliveries', function () {
    [$webhook, $token] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$token}", ['text' => 'one'])->assertCreated();
    $this->postJson("/hooks/{$token}", ['text' => 'two'])->assertCreated();

    expect(User::query()->where('is_bot', true)->count())->toBe(1)
        ->and($webhook->fresh()->delivery_count)->toBe(2)
        ->and($webhook->fresh()->last_delivered_at)->not->toBeNull();
});

it('gives each hook its own bot', function () {
    [, $first] = makeWebhook($this->channel, ['name' => 'CI']);
    [, $second] = makeWebhook($this->channel, ['name' => 'Alerts']);

    $this->postJson("/hooks/{$first}", ['text' => 'built'])->assertCreated();
    $this->postJson("/hooks/{$second}", ['text' => 'paged'])->assertCreated();

    expect(User::query()->where('is_bot', true)->pluck('name')->sort()->values()->all())
        ->toBe(['Alerts', 'CI']);
});

it('refuses an unknown token', function () {
    $this->postJson('/hooks/'.str_repeat('a', 48), ['text' => 'hello'])
        ->assertNotFound()
        ->assertJson(['ok' => false]);

    expect(Message::query()->count())->toBe(0);
});

it('refuses a revoked hook', function () {
    [, $token] = makeWebhook($this->channel, ['revoked_at' => now()]);

    // The same 404 an unknown token gets, so the endpoint cannot be used to
    // confirm which tokens are real.
    $this->postJson("/hooks/{$token}", ['text' => 'hello'])->assertNotFound();

    expect(Message::query()->count())->toBe(0);
});

it('refuses to post into an archived channel', function () {
    [, $token] = makeWebhook($this->channel);

    $this->channel->update(['is_archived' => true]);

    $this->postJson("/hooks/{$token}", ['text' => 'hello'])->assertStatus(410);

    expect(Message::query()->count())->toBe(0);
});

it('needs no CSRF token, session, or login', function () {
    [, $token] = makeWebhook($this->channel);

    // The whole point of routes/webhooks.php: a deploy script has none of the
    // three, and a route inside the `web` group would reject every delivery.
    $this->post("/hooks/{$token}", ['text' => 'from curl'])->assertCreated();
});

it('rejects a payload with nothing to post', function () {
    [, $token] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$token}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('rejects text past the ceiling', function () {
    [, $token] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$token}", [
        'text' => str_repeat('a', config('snack.webhooks.max_text_length') + 1),
    ])->assertStatus(422);
});

it('accepts Slack\'s form-encoded payload envelope', function () {
    [, $token] = makeWebhook($this->channel);

    // What a decade of existing integrations emit. See DeliverWebhookRequest.
    $this->post("/hooks/{$token}", [
        'payload' => json_encode(['text' => 'from a legacy client']),
    ])->assertCreated();

    expect(Message::query()->firstOrFail()->body)->toBe('from a legacy client');
});

it('throttles a runaway caller per hook', function () {
    config(['snack.webhooks.rate_limit_per_minute' => 2]);

    [, $token] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$token}", ['text' => 'one'])->assertCreated();
    $this->postJson("/hooks/{$token}", ['text' => 'two'])->assertCreated();
    $this->postJson("/hooks/{$token}", ['text' => 'three'])->assertStatus(429);
});

it('throttles one hook without silencing another', function () {
    config(['snack.webhooks.rate_limit_per_minute' => 1]);

    [, $noisy] = makeWebhook($this->channel);
    [, $quiet] = makeWebhook($this->channel);

    $this->postJson("/hooks/{$noisy}", ['text' => 'one'])->assertCreated();
    $this->postJson("/hooks/{$noisy}", ['text' => 'two'])->assertStatus(429);

    // Keyed by token, not by IP — the two hooks share an address here exactly as
    // two jobs on one CI runner would.
    $this->postJson("/hooks/{$quiet}", ['text' => 'still fine'])->assertCreated();
});
