<?php

use App\Models\User;

it('lists the workspaces a person belongs to', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($owner))
        ->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertJsonPath('data.0.slug', $workspace->slug);
});

it('opens a workspace with its sidebar', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($owner))
        ->getJson("/api/v1/w/{$workspace->slug}")
        ->assertOk()
        ->assertJsonPath('data.workspace.slug', $workspace->slug);
});

it('hides a workspace from a non-member', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken(User::factory()->create()))
        ->getJson("/api/v1/w/{$workspace->slug}")
        ->assertNotFound();
});

it('creates a workspace', function () {
    $user = User::factory()->create();

    $this->withToken(mobileToken($user))
        ->postJson('/api/v1/workspaces', ['name' => 'Pedi Mobile'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Pedi Mobile');
});
