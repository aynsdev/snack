<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->has('connectors', 0));
});

it('issues a token bound to the workspace and shows it once', function () {
    $response = $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Claude Desktop'])
        ->assertOk();

    $token = PersonalAccessToken::query()->sole();

    expect($token->workspace_id)->toBe($this->workspace->id)
        ->and($token->tokenable_id)->toBe($this->member->id)
        ->and($token->can(PersonalAccessToken::MCP_ABILITY))->toBeTrue();

    $response->assertInertia(fn (Assert $page) => $page
        ->hasFlash('connector.name', 'Claude Desktop')
        ->hasFlash('connector.token'));

    // The next visit no longer carries the secret.
    $this->get("/w/{$this->workspace->slug}/connectors")
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('connector')
            ->has('connectors', 1));

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
    $this->owner->createToken('owner token', [PersonalAccessToken::MCP_ABILITY])
        ->accessToken->forceFill(['workspace_id' => $this->workspace->id])->save();

    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/connectors")
        ->assertInertia(fn (Assert $page) => $page->has('connectors', 0));
});

it('revokes a connector', function () {
    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Old laptop']);

    $token = PersonalAccessToken::query()->sole();

    $this->delete("/w/{$this->workspace->slug}/connectors/{$token->id}")
        ->assertRedirect("/w/{$this->workspace->slug}/connectors");

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectorRevoked)->count())->toBe(1);
});

it('cannot revoke somebody else\'s connector', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Owner laptop']);

    $token = PersonalAccessToken::query()->sole();

    $this->actingAs($this->member)
        ->delete("/w/{$this->workspace->slug}/connectors/{$token->id}")
        ->assertNotFound();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('caps how many connectors one person may hold', function () {
    config()->set('snack.mcp.max_tokens_per_member', 1);

    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'First']);

    $this->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Second'])
        ->assertSessionHasErrors('name');

    expect(PersonalAccessToken::query()->count())->toBe(1);
});
