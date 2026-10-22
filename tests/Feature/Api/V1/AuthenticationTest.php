<?php

use App\Actions\Connectors\CreateConnectorToken;
use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

it('trades credentials for a mobile token', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 9',
    ]);

    $response->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email);

    $this->withToken($response->json('access_token'))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('rejects a wrong password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Pixel 9',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('asks for a two-factor code before issuing a token', function () {
    $twoFactor = app(TwoFactorAuthenticationProvider::class);
    $secret = $twoFactor->generateSecretKey();

    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $credentials = ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel 9'];

    $this->postJson('/api/v1/auth/token', $credentials)
        ->assertUnprocessable()
        ->assertJsonPath('two_factor', true)
        ->assertJsonMissingPath('access_token');

    $this->postJson('/api/v1/auth/token', [...$credentials, 'code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('two_factor', true);

    $this->postJson('/api/v1/auth/token', [...$credentials, 'recovery_code' => 'recovery-code-1'])
        ->assertCreated();

    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');
});

it('accepts a valid authenticator code', function () {
    $twoFactor = app(TwoFactorAuthenticationProvider::class);
    $secret = $twoFactor->generateSecretKey();

    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 9',
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertCreated();
});

it('signs only this device out', function () {
    $user = User::factory()->create();
    $phone = mobileToken($user);
    $tablet = mobileToken($user);

    $this->withToken($phone)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($user->tokens()->where('revoked', true)->count())->toBe(1)
        ->and($user->tokens()->where('revoked', false)->count())->toBe(1);

    app('auth')->forgetGuards();

    $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
});

it('requires a token', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('turns away an MCP connector token', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $token = app(CreateConnectorToken::class)->handle($workspace, $owner, 'Claude')->accessToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
});

it('keeps mobile tokens out of the MCP server', function () {
    [, $owner] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($owner))
        ->postJson('/mcp', mcpCall('tools/list'))
        ->assertForbidden();
});

it('emails a password reset link without revealing who has an account', function () {
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertAccepted();
});
