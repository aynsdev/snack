<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;

/**
 * The sidebar's direct message list.
 *
 * It is a roster of the workspace, not a list of threads. Listing conversations
 * meant a colleague only appeared once you had started one with them, so the
 * only way to see the team in the sidebar was to create a conversation row per
 * member. Nothing here creates anything: a person with no thread is a name, and
 * the conversation is opened by the click that needs it.
 *
 * The payload rides on the shared `snack` prop, so any workspace-scoped page
 * serves it. The members index is the default here because it needs no fixtures
 * of its own — but a guest may not open it, so those tests pass a page they can
 * actually reach.
 */
function sidebarChrome(Workspace $workspace, ?string $url = null): array
{
    $response = test()->get($url ?? route('members.index', ['workspace' => $workspace->slug]));

    $response->assertOk();

    return $response->viewData('page')['props']['snack']['current'];
}

function sidebarPeople(Workspace $workspace, ?string $url = null): array
{
    return sidebarChrome($workspace, $url)['people'];
}

/**
 * @return array<int, string>
 */
function sidebarPersonNames(Workspace $workspace): array
{
    return array_column(array_column(sidebarPeople($workspace), 'user'), 'name');
}

it('lists a member you have never messaged', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    $this->actingAs($ada);

    expect(sidebarPersonNames($workspace))->toContain('Bo');

    // The whole point: rendering the list is not what creates the thread.
    expect(Conversation::count())->toBe(0);
});

it('leaves you out of your own list', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    $this->actingAs($ada);

    expect(sidebarPersonNames($workspace))->not->toContain('Ada');
});

it('leaves out a suspended member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    joinWorkspace(
        $workspace,
        User::factory()->create(['name' => 'Suspended Sam']),
        status: MemberStatus::Suspended,
    );

    // Suspended is treated exactly like removed everywhere else; offering them
    // a row would produce a message the server then refuses to deliver.
    $this->actingAs($ada);

    expect(sidebarPersonNames($workspace))->not->toContain('Suspended Sam');
});

it('leaves out a member of another workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));

    $elsewhere = makeWorkspace();
    joinWorkspace($elsewhere, User::factory()->create(['name' => 'Outsider']));

    $this->actingAs($ada);

    expect(sidebarPersonNames($workspace))->not->toContain('Outsider');
});

it('carries the direct conversation a person already has with you', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    $bo = joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));
    $cy = joinWorkspace($workspace, User::factory()->create(['name' => 'Cy']));

    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);

    $people = collect(sidebarPeople($workspace))->keyBy(fn (array $person): string => $person['user']['name']);

    expect($people['Bo']['conversation']['id'])->toBe($conversation->public_id);

    // And nothing invented for the person you have not messaged.
    expect($people['Cy']['conversation'])->toBeNull();
});

it('does not hang a group conversation off a person', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    $bo = joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));
    $cy = joinWorkspace($workspace, User::factory()->create(['name' => 'Cy']));

    /*
     * A group is a room of its own and stays in the conversation list. Filing
     * it under one of its members would title it by that person and give the
     * same thread two rows.
     */
    makeConversation($workspace, [$ada, $bo, $cy], 'Launch');

    $this->actingAs($ada);

    $people = collect(sidebarPeople($workspace))->keyBy(fn (array $person): string => $person['user']['name']);

    expect($people['Bo']['conversation'])->toBeNull();
    expect($people['Cy']['conversation'])->toBeNull();
});

it('orders recently active threads above the rest of the roster', function () {
    $workspace = makeWorkspace();
    $owner = joinWorkspace($workspace, $workspace->owner, WorkspaceRole::Owner);
    $owner->update(['name' => 'Zed']);

    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));
    $cy = joinWorkspace($workspace, User::factory()->create(['name' => 'Cy']));
    $di = joinWorkspace($workspace, User::factory()->create(['name' => 'Di']));

    makeConversation($workspace, [$ada, $cy])
        ->update(['last_message_at' => now()->subMinute()]);
    makeConversation($workspace, [$ada, $di])
        ->update(['last_message_at' => now()]);

    $this->actingAs($ada);

    /*
     * The people you are actually talking to keep the ordering the DM list had
     * when it held conversations alone; everyone else is appended by name
     * rather than interleaved into it, so the roster stays scannable.
     */
    expect(sidebarPersonNames($workspace))->toBe(['Di', 'Cy', 'Bo', 'Zed']);
});

it('serves the DM home from the props the page already has', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    $this->actingAs($ada);

    /*
     * The page the navigation rail's DMs item points at, in the one case where
     * it renders: nothing to open yet. It draws the roster from the shared
     * `snack` prop rather than props of its own — asking the server for the same
     * list twice on one request is how a page and the sidebar beside it start
     * disagreeing.
     */
    $this->get(route('conversations.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('conversations/index')
            ->has('snack.current.people', 2));

    // And landing there did not start a thread with whoever sorts first.
    expect(Conversation::count())->toBe(0);
});

it('opens the top of the DM list rather than a list of lists', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    $bo = joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));
    $cy = joinWorkspace($workspace, User::factory()->create(['name' => 'Cy']));

    makeConversation($workspace, [$ada, $bo])
        ->update(['last_message_at' => now()->subDay()]);
    $newest = makeConversation($workspace, [$ada, $cy]);
    $newest->update(['last_message_at' => now()]);

    $this->actingAs($ada);

    // The row the reader would have clicked anyway. Landing on a list beside a
    // list would make them choose twice to get where they were going.
    $this->get(route('conversations.index', ['workspace' => $workspace->slug]))
        ->assertRedirect(route('conversations.show', [
            'workspace' => $workspace->slug,
            'conversation' => $newest->public_id,
        ]));
});

it('never opens a conversation someone else is in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    $bo = joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    // Somebody else's thread, more recent than anything Ada has.
    makeConversation($workspace, [$owner, $bo])->update(['last_message_at' => now()]);

    $this->actingAs($ada);

    $this->get(route('conversations.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('conversations/index'));
});

it('gives a guest no roster, but keeps the conversations they are in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, User::factory()->create(['name' => 'Guest']), WorkspaceRole::Guest);
    joinWorkspace($workspace, User::factory()->create(['name' => 'Bo']));

    $conversation = makeConversation($workspace, [$guest, $owner]);

    $channel = makeChannel($workspace);
    addToChannel($channel, $guest);

    $this->actingAs($guest);

    $chrome = sidebarChrome($workspace, route('channels.show', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]));

    /*
     * The same rule the new-message picker enforces. A guest may not start a
     * conversation, so shipping them the member directory in the sidebar would
     * be a back door to the list the picker refuses them — while the threads
     * they were *invited* into must still be there to open.
     */
    expect($chrome['people'])->toBe([]);
    expect(array_column($chrome['conversations'], 'id'))->toBe([$conversation->public_id]);
});
