<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * The people offered when starting a conversation.
 *
 * The picker and the store endpoint have to agree about who is reachable. A name
 * offered here that the server then refuses is worse than an absent one: the
 * person chose a colleague and got a validation error for it.
 */
function directoryFor(Workspace $workspace, string $term = ''): array
{
    $response = test()->getJson(route('conversations.directory.index', array_filter([
        'workspace' => $workspace->slug,
        'q' => $term === '' ? null : $term,
    ])));

    $response->assertOk();

    return array_column($response->json('people'), 'name');
}

it('offers the other active members of the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    $this->actingAs($ada);

    expect(directoryFor($workspace))->toContain('Bo');
});

it('leaves you out of your own picker', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    $this->actingAs($ada);

    // Messaging yourself is not a conversation Snack models.
    expect(directoryFor($workspace))->not->toContain('Ada');
});

it('does not offer a suspended member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    joinWorkspace(
        $workspace,
        User::factory()->create(['name' => 'Suspended Sam']),
        status: MemberStatus::Suspended,
    );

    $this->actingAs($ada);

    /*
     * Caught in the browser: the picker listed a suspended colleague that
     * StoreConversationRequest then rejected. A suspended member is treated as a
     * non-member everywhere else, and this is no exception.
     */
    expect(directoryFor($workspace))->not->toContain('Suspended Sam');
});

it('does offer a guest, who can be messaged even though they cannot start one', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    joinWorkspace(
        $workspace,
        User::factory()->create(['name' => 'Guest Gwen']),
        role: WorkspaceRole::Guest,
    );

    $this->actingAs($ada);

    expect(directoryFor($workspace))->toContain('Guest Gwen');
});

it('never offers someone from another workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    [$otherWorkspace] = makeWorkspaceWithOwner();
    joinWorkspace($otherWorkspace, User::factory()->create(['name' => 'Outsider Otto']));

    $this->actingAs($ada);

    expect(directoryFor($workspace))->not->toContain('Outsider Otto');
});

it('filters by name and by username', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    joinWorkspace($workspace, User::factory()->create([
        'name' => 'Grace Hopper',
        'username' => 'grace.hopper',
    ]));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo Diddley']));

    $this->actingAs($ada);

    expect(directoryFor($workspace, 'grace'))->toBe(['Grace Hopper'])
        ->and(directoryFor($workspace, 'hopper'))->toBe(['Grace Hopper'])
        // Matching is case-insensitive, because nobody types a colleague's name
        // with their capitalisation in mind.
        ->and(directoryFor($workspace, 'GRACE'))->toBe(['Grace Hopper'])
        ->and(directoryFor($workspace, 'diddley'))->toBe(['Bo Diddley']);
});

it('refuses the directory to a guest', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    joinWorkspace($workspace);

    $this->actingAs($guest);

    // A guest cannot start a conversation, so this must not become a back door
    // to the workspace's member directory for them.
    $this->getJson(route('conversations.directory.index', ['workspace' => $workspace->slug]))
        ->assertForbidden();
});

it('refuses the directory to someone outside the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    $this->actingAs($outsider)
        ->getJson(route('conversations.directory.index', ['workspace' => $workspace->slug]))
        ->assertNotFound();
});
