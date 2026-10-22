<?php

use App\Enums\WorkspaceRole;
use App\Models\McpGrant;
use App\Models\OAuthToken;

/**
 * AI connectors over the API: per person, and the plaintext token comes back
 * once, in the response that issued it.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists a member\'s connectors, not counting the mobile token', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/connectors")
        ->assertOk()
        ->assertJsonPath('data.server_url', url('/mcp'))
        ->assertJsonCount(0, 'data.apps')
        ->assertJsonCount(0, 'data.tokens');
});

it('issues a token and returns the plaintext once', function () {
    $plainText = $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/connectors", ['name' => 'Cursor'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cursor')
        ->json('data.token');

    expect($plainText)->toBeString()->not->toBeEmpty();

    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/connectors")
        ->assertJsonCount(1, 'data.tokens')
        ->assertJsonMissingPath('data.tokens.0.token');
});

it('revokes a token', function () {
    $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/connectors", ['name' => 'Old laptop']);

    $token = OAuthToken::query()->where('workspace_id', $this->workspace->id)->sole();

    $this->withToken(mobileToken($this->member))
        ->deleteJson("{$this->base}/connectors/tokens/{$token->id}")
        ->assertNoContent();

    expect($token->fresh()->revoked)->toBeTrue();
});

it('cannot revoke somebody else\'s token', function () {
    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/connectors", ['name' => 'Owner laptop']);

    $token = OAuthToken::query()->where('workspace_id', $this->workspace->id)->sole();

    // The guard remembers who it resolved; a real request starts fresh.
    auth()->forgetGuards();

    $this->withToken(mobileToken($this->member))
        ->deleteJson("{$this->base}/connectors/tokens/{$token->id}")
        ->assertNotFound();

    expect($token->fresh()->revoked)->toBeFalse();
});

it('disconnects an app that signed in', function () {
    $grant = McpGrant::factory()->create(['user_id' => $this->member->id, 'workspace_id' => $this->workspace->id]);

    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/connectors")
        ->assertJsonCount(1, 'data.apps');

    $this->withToken(mobileToken($this->member))
        ->deleteJson("{$this->base}/connectors/apps/{$grant->id}")
        ->assertNoContent();

    expect(McpGrant::query()->find($grant->id))->toBeNull();
});
