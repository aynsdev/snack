<?php

use App\Enums\WorkspaceRole;
use App\Models\IncomingWebhook;

/**
 * Incoming webhooks over the API — admin-gated including the list, and every
 * write answers with the hook's live URL and secret.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists the hooks for an admin', function () {
    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/webhooks")
        ->assertOk()
        ->assertJsonCount(0, 'data.webhooks')
        ->assertJsonPath('data.channels.0.id', $this->channel->id)
        ->assertJsonStructure(['data' => ['limits' => ['max_text_length', 'rate_limit_per_minute']]]);
});

it('refuses the hooks to an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/webhooks")
        ->assertForbidden();
});

it('creates a hook and returns its working URL', function () {
    $url = $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/webhooks", ['name' => 'Deploys', 'channel_id' => $this->channel->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Deploys')
        ->assertJsonPath('data.channel.id', $this->channel->id)
        ->json('data.url');

    $webhook = IncomingWebhook::query()->sole();

    expect($url)->toBe($webhook->url());

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'it works'])->assertCreated();
});

it('refuses a channel from another workspace', function () {
    $elsewhere = makeChannel(makeWorkspace());

    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/webhooks", ['name' => 'Cross tenant', 'channel_id' => $elsewhere->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');
});

it('renames, revokes, restores, signs and deletes a hook', function () {
    $id = $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/webhooks", ['name' => 'Deploys', 'channel_id' => $this->channel->id])
        ->json('data.id');

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$id}", ['name' => 'CI', 'default_icon' => 'rocket'])
        ->assertOk()
        ->assertJsonPath('data.name', 'CI')
        ->assertJsonPath('data.default_icon', 'rocket');

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$id}/revoke")
        ->assertOk()
        ->assertJsonPath('data.is_revoked', true)
        ->assertJsonPath('data.url', null);

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$id}/restore")
        ->assertOk()
        ->assertJsonPath('data.is_revoked', false);

    $secret = $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$id}/signing", ['mode' => 'enable'])
        ->assertOk()
        ->assertJsonPath('data.signing_required', true)
        ->json('data.signing_secret');

    expect($secret)->toBeString()->not->toBeEmpty();

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$id}/signing", ['mode' => 'rotate'])
        ->assertOk()
        ->assertJsonPath('data.signing_required', true);

    expect(IncomingWebhook::query()->find($id)->signing_secret)->not->toBe($secret);

    $this->withToken(mobileToken($this->owner))
        ->deleteJson("{$this->base}/webhooks/{$id}")
        ->assertNoContent();

    expect(IncomingWebhook::query()->find($id))->toBeNull();
});

it('hides a hook from another workspace', function () {
    [$other, $otherOwner] = makeWorkspaceWithOwner();
    $webhook = IncomingWebhook::factory()->create([
        'workspace_id' => $other->id,
        'channel_id' => makeChannel($other)->id,
        'created_by' => $otherOwner->id,
    ]);

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/webhooks/{$webhook->id}/revoke")
        ->assertNotFound();
});
