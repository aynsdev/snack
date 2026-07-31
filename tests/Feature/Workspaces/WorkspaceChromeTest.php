<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;

/**
 * The workspace chrome on pages that are not themselves workspace-scoped.
 *
 * Settings live outside the `/w/{workspace}` prefix, so nothing binds a tenant
 * to them and the shell would render with no rail and no sidebar. The workspace
 * recorded by ResolveWorkspace dresses them instead — but the session value
 * naming it is re-resolved and re-authorized every time, because a session
 * outlives being removed from a workspace.
 *
 * # Why the session is seeded directly
 *
 * The obvious setup — visit a workspace page first, then the settings page —
 * does not test this. `WorkspaceContext` is a scoped binding, and the test
 * application does not flush scoped instances between `$this->get()` calls, so
 * the second request still sees the first request's *resolved* context and
 * never reaches the fallback at all. (Production is unaffected: php-fpm gets a
 * fresh process per request, and Octane flushes scoped bindings.) Seeding the
 * session is what actually exercises the path under test.
 */
it('dresses settings with the workspace the user was last in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->withSession(['snack.current_workspace' => $workspace->slug])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('snack.current.workspace.slug', $workspace->slug));
});

it('leaves settings bare for someone who has not been in a workspace', function () {
    // Nothing to dress it with, and inventing one would be a guess.
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('snack.current', null));
});

it('refuses a remembered workspace the user no longer belongs to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $stranger = User::factory()->create();

    /*
     * Honouring the slug would rebuild this workspace's channel list and unread
     * counts for somebody who was never in it — a session naming a workspace is
     * not evidence of membership.
     */
    $this->actingAs($stranger)
        ->withSession(['snack.current_workspace' => $workspace->slug])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('snack.current', null));
});

it('refuses a remembered workspace once the membership is suspended', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, status: MemberStatus::Suspended);

    // Suspended is treated exactly like removed everywhere else; the chrome
    // must not be the one place that still shows them the room.
    $this->actingAs($member)
        ->withSession(['snack.current_workspace' => $workspace->slug])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('snack.current', null));
});

it('refuses a remembered workspace that no longer exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['snack.current_workspace' => 'deleted-workspace'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('snack.current', null));
});

it('prefers the workspace in the route over the remembered one', function () {
    [$remembered, $owner] = makeWorkspaceWithOwner();
    $current = makeWorkspace();
    joinWorkspace($current, $owner, WorkspaceRole::Owner);

    // The route is the authority; the session is only a fallback.
    $this->actingAs($owner)
        ->withSession(['snack.current_workspace' => $remembered->slug])
        ->get(route('members.index', ['workspace' => $current->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('snack.current.workspace.slug', $current->slug));
});
