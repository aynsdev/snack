<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\McpGrant;
use App\Models\OAuthToken;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Server\Registrar;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
});

it('shows any member their connectors page', function () {
    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/connectors")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/connectors')
            ->where('server_url', url('/mcp'))
            ->has('apps', 0)
            ->has('tokens', 0));
});

it('issues a token bound to the workspace and shows it once', function () {
    $response = $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Cursor'])
        ->assertOk();

    $token = OAuthToken::query()->sole();

    expect($token->workspace_id)->toBe($this->workspace->id)
        ->and($token->user_id)->toBe($this->member->id)
        ->and($token->scopes)->toBe([Registrar::OAUTH_SCOPE]);

    $response->assertInertia(fn (Assert $page) => $page
        ->hasFlash('connector.name', 'Cursor')
        ->hasFlash('connector.token'));

    // The next visit no longer carries the secret.
    $this->get("/w/{$this->workspace->slug}/connectors")
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('connector')
            ->has('tokens', 1));

    expect(AuditLog::query()->where('action', AuditAction::ConnectorCreated)->count())->toBe(1);
});

it('issues a token that actually opens the MCP server', function () {
    $response = $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Cursor']);

    $plainText = $response->inertiaProps('flash.connector.token')
        ?? $response->viewData('page')['flash']['connector']['token'];

    auth()->forgetGuards();

    $this->withToken($plainText)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
        ->assertOk();
});

it('lists only the person\'s own connectors', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Owner laptop']);
    McpGrant::factory()->create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id]);

    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/connectors")
        ->assertInertia(fn (Assert $page) => $page->has('tokens', 0)->has('apps', 0));
});

it('revokes a token', function () {
    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Old laptop']);

    $token = OAuthToken::query()->sole();

    $this->delete("/w/{$this->workspace->slug}/connectors/tokens/{$token->id}")
        ->assertRedirect("/w/{$this->workspace->slug}/connectors");

    expect($token->fresh()->revoked)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectorRevoked)->count())->toBe(1);
});

it('cannot revoke somebody else\'s token', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Owner laptop']);

    $token = OAuthToken::query()->sole();

    $this->actingAs($this->member)
        ->delete("/w/{$this->workspace->slug}/connectors/tokens/{$token->id}")
        ->assertNotFound();

    expect($token->fresh()->revoked)->toBeFalse();
});

it('disconnects a signed-in app and revokes what it holds', function () {
    $grant = McpGrant::factory()->create(['user_id' => $this->member->id, 'workspace_id' => $this->workspace->id]);
    $token = OAuthToken::query()->forceCreate([
        'id' => str_repeat('a', 80),
        'user_id' => $this->member->id,
        'client_id' => $grant->client_id,
        'scopes' => [Registrar::OAUTH_SCOPE],
        'revoked' => false,
    ]);

    $this->actingAs($this->member)
        ->delete("/w/{$this->workspace->slug}/connectors/apps/{$grant->id}")
        ->assertRedirect();

    expect(McpGrant::query()->count())->toBe(0)
        ->and($token->fresh()->revoked)->toBeTrue();
});

it('cannot disconnect somebody else\'s app', function () {
    $grant = McpGrant::factory()->create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id]);

    $this->actingAs($this->member)
        ->delete("/w/{$this->workspace->slug}/connectors/apps/{$grant->id}")
        ->assertNotFound();

    expect(McpGrant::query()->count())->toBe(1);
});

it('caps how many tokens one person may hold', function () {
    config()->set('snack.mcp.max_tokens_per_member', 1);

    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'First']);

    $this->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Second'])
        ->assertSessionHasErrors('name');

    expect(OAuthToken::query()->count())->toBe(1);
});
