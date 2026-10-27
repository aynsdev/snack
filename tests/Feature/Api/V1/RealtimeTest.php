<?php

use App\Enums\ChannelType;
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

it('authorizes a channel the member belongs to, as the browser would', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->withToken(mobileToken($owner))
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-channel.{$channel->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth', 'channel_data']);
});

it('authorizes a conversation for its participants', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $colleague = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$owner, $colleague]);

    $this->withToken(mobileToken($colleague))
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-conversation.{$conversation->public_id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth', 'channel_data']);
});

it('refuses a private channel to someone outside it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $secret = makeChannel($workspace, ChannelType::Private);
    $outsider = joinWorkspace($workspace);

    $this->withToken(mobileToken($outsider))
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-channel.{$secret->id}",
        ])
        ->assertForbidden();
});

it('still refuses a bearer token at the browser\'s endpoint', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->withToken(mobileToken($owner))
        ->get('/broadcasting/auth?'.http_build_query([
            'socket_id' => '1234.5678',
            'channel_name' => "presence-channel.{$channel->id}",
        ]))
        ->assertForbidden();
});
