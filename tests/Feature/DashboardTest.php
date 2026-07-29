<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

// Snack has no dashboard: signing in lands you in a workspace, or on the picker
// when you belong to none. The route is kept as a redirect so any bookmark or
// third-party link to /dashboard still arrives somewhere sensible.
test('the dashboard route sends authenticated users to their workspaces', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('workspaces.index'));
});

test('a user with no workspaces sees the workspace picker', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/index'));
});

test('a user with exactly one workspace is sent straight into it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->get(route('workspaces.index'))
        ->assertRedirect(route('workspaces.show', $workspace));
});
