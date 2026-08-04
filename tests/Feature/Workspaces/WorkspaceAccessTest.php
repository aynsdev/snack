<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;

/**
 * Tenant isolation is the single most important rule in Snack: nothing about a
 * workspace may be observable to someone who is not an active member of it.
 *
 * Denials are asserted as 404 rather than 403 on purpose — a 403 would confirm
 * that a workspace with that slug exists, which is enough to enumerate
 * customers once Snack is multi-tenant.
 */
it('drops an active member into their first channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $channel = makeChannel($workspace, attributes: ['is_default' => true]);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->get(route('workspaces.show', $workspace))
        ->assertRedirect(route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]));
});

/**
 * The landing channel is the server's choice, not the reader's, and the page it
 * lands on has to be able to tell the difference — on a phone the shell opens
 * its navigation over a room you did not pick, and leaves a room you did alone.
 */
it('marks the landing channel as the one the server chose', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $channel = makeChannel($workspace, attributes: ['is_default' => true]);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->get(route('workspaces.show', $workspace))
        ->assertSessionHas('entered_workspace', true);

    // Followed through, because the flash is only useful if the page on the
    // other side of the redirect can actually read it.
    $this->actingAs($member)
        ->followingRedirects()
        ->get(route('workspaces.show', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('flash.entered_workspace', true));
});

it('leaves a channel asked for by name unmarked', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->get(route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('flash.entered_workspace', false));
});

it('shows an empty state to a member who is in no channels', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    // A channel exists, but they have not joined it.
    makeChannel($workspace);

    $this->actingAs($member)
        ->get(route('workspaces.show', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/empty'));
});

it('hides a workspace from someone who is not a member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspaces.show', $workspace))
        ->assertNotFound();
});

it('hides a workspace from a member of a different workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    [$otherWorkspace] = makeWorkspaceWithOwner();

    $outsider = joinWorkspace($otherWorkspace);

    $this->actingAs($outsider)
        ->get(route('workspaces.show', $workspace))
        ->assertNotFound();
});

it('locks out a suspended member without deleting their membership', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $suspended = joinWorkspace($workspace, status: MemberStatus::Suspended);

    $this->actingAs($suspended)
        ->get(route('workspaces.show', $workspace))
        ->assertNotFound();

    // The row survives, so reinstating restores everything.
    expect($workspace->members()->where('user_id', $suspended->id)->exists())->toBeTrue();
});

it('reports no role for a suspended member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $suspended = joinWorkspace($workspace, role: WorkspaceRole::Admin, status: MemberStatus::Suspended);

    expect($suspended->roleIn($workspace))->toBeNull()
        ->and($suspended->belongsToWorkspace($workspace))->toBeFalse();
});

it('redirects guests to the login page', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $this->get(route('workspaces.show', $workspace))
        ->assertRedirect(route('login'));
});

it('only lists workspaces the user actually belongs to', function () {
    [$mine] = makeWorkspaceWithOwner();
    [$theirs] = makeWorkspaceWithOwner();

    $user = joinWorkspace($mine);

    expect($user->workspaces()->pluck('workspaces.id')->all())
        ->toBe([$mine->id])
        ->not->toContain($theirs->id);
});

it('scopes a channel lookup to its own workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    [$otherWorkspace] = makeWorkspaceWithOwner();

    $member = joinWorkspace($workspace);
    $foreignChannel = makeChannel($otherWorkspace, attributes: ['name' => 'secrets', 'slug' => 'secrets']);

    // Same slug, wrong workspace: scoped route bindings must not resolve it.
    $this->actingAs($member)
        ->get(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $foreignChannel->slug]))
        ->assertNotFound();
});
