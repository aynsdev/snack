<?php

use App\Models\User;

it('describes the installation to the app before sign-in', function () {
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.token_scope', 'mobile')
        ->assertJsonPath('data.broadcasting.auth_endpoint', url('/api/v1/broadcasting/auth'))
        ->assertJsonStructure(['data' => ['broadcasting' => ['key', 'host', 'port', 'scheme'], 'features']]);
});

it('authorizes a private channel subscription with the bearer token', function () {
    $user = User::factory()->create();

    $this->withToken(mobileToken($user))
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$user->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('refuses somebody else\'s private channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->withToken(mobileToken($user))
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$other->id}",
        ])
        ->assertForbidden();
});
