<?php

use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use App\Enums\WorkspaceRole;
use App\Models\BiteMemory;

/**
 * Bite's workspace configuration and knowledge base — admin-gated including
 * the read, as on the web.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('shows an admin the Bite settings', function () {
    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/bite")
        ->assertOk()
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.settings.audience', BiteAudience::Everyone->value)
        ->assertJsonPath('data.is_available', true)
        ->assertJsonStructure(['data' => ['audiences', 'channel_modes', 'capabilities', 'channels', 'memories', 'limits']]);
});

it('refuses the Bite settings to an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/bite")
        ->assertForbidden();
});

it('saves the Bite settings', function () {
    $channel = makeChannel($this->workspace);

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/bite", [
            'is_enabled' => true,
            'audience' => BiteAudience::Everyone->value,
            'channel_mode' => BiteChannelMode::Allow->value,
            'channel_ids' => [$channel->id],
            'instructions' => 'Be brief.',
            'context_messages' => 30,
            'max_steps' => null,
            'capabilities' => [BiteCapability::allValues()[0]],
        ])
        ->assertOk()
        ->assertJsonPath('data.is_configured', true)
        ->assertJsonPath('data.settings.instructions', 'Be brief.')
        ->assertJsonPath('data.settings.channel_ids', [$channel->id]);
});

it('adds, edits and removes a knowledge entry', function () {
    $id = $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/bite/memories", ['title' => 'Office hours', 'body' => 'We open at nine.'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Office hours')
        ->assertJsonPath('data.author', $this->owner->name)
        ->json('data.id');

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/bite/memories/{$id}", ['is_enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.is_enabled', false);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/bite")
        ->assertJsonCount(1, 'data.memories');

    $this->withToken(mobileToken($this->owner))
        ->deleteJson("{$this->base}/bite/memories/{$id}")
        ->assertNoContent();

    expect(BiteMemory::query()->count())->toBe(0);
});

it('refuses a knowledge entry from an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/bite/memories", ['title' => 'Office hours', 'body' => 'We open at nine.'])
        ->assertForbidden();
});
