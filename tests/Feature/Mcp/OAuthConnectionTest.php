<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\McpGrant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Connecting the way Claude does it: discover the authorization server,
 * register as a client, send the person to Snack's consent screen where they
 * pick a workspace, exchange the code, and call the MCP server with the token.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->redirect = 'https://claude.ai/api/mcp/auth_callback';
});

/**
 * Walk one person through the consent screen for a client, choosing a
 * workspace, and return the redirect Passport sends back to the client.
 *
 * @return array{0: TestResponse, 1: string}
 */
function consent(Client $client, string $redirect, int $workspaceId): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'xyz',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $response = test()->post('/oauth/authorize/workspace', [
        'auth_token' => session('authToken'),
        'workspace_id' => $workspaceId,
    ]);

    return [$response, $verifier];
}

function registerClaude(string $redirect): Client
{
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: [$redirect],
        confidential: false,
    );
}

it('publishes the discovery documents a client starts from', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('authorization_servers.0', url('/'));

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        // Without this, RFC 8414 reads as "client_secret_basic only", which
        // contradicts the public clients registration creates.
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);

    $this->getJson('/.well-known/oauth-authorization-server/mcp')
        ->assertOk()
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
});

it('answers a client id that is not a uuid with invalid_client, not a crash', function () {
    // A newer MCP client may identify itself by a metadata document URL.
    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => 'https://claude.ai/oauth/client-metadata.json',
        'redirect_uri' => $this->redirect,
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]))->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
});

it('lets Claude register itself', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$this->redirect],
    ])->assertCreated()->assertJsonStructure(['client_id']);
});

it('refuses to register a client that redirects somewhere unknown', function () {
    // Its name would be shown on the consent screen; letting any site register
    // would let any site call itself "Claude".
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://evil.example/callback'],
    ])->assertStatus(400);
});

it('asks which workspace, then connects to that one', function () {
    $channel = makeChannel($this->workspace, ChannelType::Public, ['name' => 'general', 'slug' => 'general']);
    addToChannel($channel, $this->member);
    makeMessage($channel, $this->owner, 'Launch is on Tuesday');

    $client = registerClaude($this->redirect);

    $this->actingAs($this->member);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $this->redirect,
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]))->assertOk()->assertSee($this->workspace->name);

    [$response, $verifier] = consent($client, $this->redirect, $this->workspace->id);

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith($this->redirect);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(McpGrant::query()->sole())
        ->user_id->toBe($this->member->id)
        ->workspace_id->toBe($this->workspace->id);

    auth()->forgetGuards();

    $accessToken = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $this->redirect,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json('access_token');

    $this->withToken($accessToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'read_channel', 'arguments' => ['channel' => 'general']],
        ])
        ->assertOk()
        ->assertSee('Launch is on Tuesday');
});

it('will not connect to a workspace the person is not in', function () {
    [$elsewhere] = makeWorkspaceWithOwner();
    $client = registerClaude($this->redirect);

    $this->actingAs($this->member);

    [$response] = consent($client, $this->redirect, $elsewhere->id);

    $response->assertSessionHasErrors('workspace_id');
    expect(McpGrant::query()->count())->toBe(0);
});

it('refuses an approval without the consent screen\'s token', function () {
    $this->actingAs($this->member)
        ->post('/oauth/authorize/workspace', [
            'auth_token' => 'forged',
            'workspace_id' => $this->workspace->id,
        ])
        ->assertForbidden();

    expect(McpGrant::query()->count())->toBe(0);
});

it('sends an Inertia visit to the consent screen as a full page load', function () {
    /*
     * After logging in, Fortify redirects to oauth/authorize and Inertia
     * follows that redirect as an XHR. The consent screen is Blade, so Inertia
     * would show it in a sandboxed dialog where its form cannot submit.
     */
    $client = registerClaude($this->redirect);
    $url = '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $this->redirect,
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]);

    $location = $this->actingAs($this->member)
        ->get($url, ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->headers->get('X-Inertia-Location');

    // The same request, loaded for real (the query comes back sorted).
    expect($location)->toStartWith(url('/oauth/authorize?'))
        ->and($location)->toContain('client_id='.$client->getKey());
});
