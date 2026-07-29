<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Events\ChannelCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Channel visibility and posting rights.
 *
 * The rules under test:
 *  - public and announcement channels are previewable by any non-guest member;
 *  - private channels require membership and are otherwise invisible;
 *  - guests only ever see channels they were explicitly added to;
 *  - posting always requires membership, and announcement channels additionally
 *    require workspace admin.
 */
it('lets a member preview a public channel they have not joined', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($member)
        ->get(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $channel->slug]))
        ->assertOk();
});

it('hides a private channel from a member who was not invited', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($member)
        ->get(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $channel->slug]))
        ->assertNotFound();
});

it('lets an invited member into a private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->get(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $channel->slug]))
        ->assertOk();
});

it('hides a private channel even from a workspace admin who is not a member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $channel = makeChannel($workspace, ChannelType::Private);

    // Administering a workspace does not grant a window into private rooms.
    expect(Gate::forUser($admin)->allows('view', $channel))->toBeFalse();
});

it('confines a guest to the channels they were added to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $assigned = makeChannel($workspace);
    $unassigned = makeChannel($workspace);
    addToChannel($assigned, $guest);

    expect(Gate::forUser($guest)->allows('view', $assigned))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('view', $unassigned))->toBeFalse();
});

it('keeps guests out of the channel browser', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->get(route('channels.index', $workspace))
        ->assertForbidden();
});

it('stops guests from creating channels', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->post(route('channels.store', $workspace), [
            'name' => 'guest-channel',
            'type' => ChannelType::Public->value,
        ])
        ->assertForbidden();

    expect($workspace->channels()->where('name', 'guest-channel')->exists())->toBeFalse();
});

it('requires channel membership before posting', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    // Viewable, but not postable until joined — "post" is strictly narrower.
    expect(Gate::forUser($member)->allows('view', $channel))->toBeTrue()
        ->and(Gate::forUser($member)->allows('post', $channel))->toBeFalse();

    addToChannel($channel, $member);

    expect(Gate::forUser($member->fresh())->allows('post', $channel->fresh()))->toBeTrue();
});

it('restricts posting in an announcement channel to workspace admins', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace, ChannelType::Announcement);

    $member = joinWorkspace($workspace);
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    addToChannel($channel, $member);
    addToChannel($channel, $admin);

    expect(Gate::forUser($member)->allows('post', $channel))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('post', $channel))->toBeTrue();
});

it('lets everyone read an announcement channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Announcement);

    expect(Gate::forUser($member)->allows('view', $channel))->toBeTrue();
});

it('refuses posting in an archived channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, attributes: ['is_archived' => true]);
    addToChannel($channel, $member);

    expect(Gate::forUser($member)->allows('post', $channel))->toBeFalse();
});

it('only lets admins create announcement channels', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->post(route('channels.store', $workspace), [
            'name' => 'company-news',
            'type' => ChannelType::Announcement->value,
        ])
        ->assertSessionHasErrors('type');
});

it('creates a channel and enrolls its creator as a channel admin', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->post(route('channels.store', $workspace), [
            'name' => 'Product Launch',
            'description' => 'Shipping the thing.',
            'type' => ChannelType::Public->value,
        ])
        ->assertRedirect();

    $channel = $workspace->channels()->where('slug', 'product-launch')->firstOrFail();

    expect($channel->is_private)->toBeFalse()
        ->and($channel->membershipFor($member)?->isChannelAdmin())->toBeTrue();
});

it('announces a new public channel to the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)->post(route('channels.store', $workspace), [
        'name' => 'open-house',
        'type' => ChannelType::Public->value,
    ]);

    Event::assertDispatched(ChannelCreated::class);
});

it('never announces a new private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)->post(route('channels.store', $workspace), [
        'name' => 'hush-hush',
        'type' => ChannelType::Private->value,
    ]);

    // Broadcasting this would reveal the channel's existence — and its name —
    // to every member of the workspace.
    Event::assertNotDispatched(ChannelCreated::class);
});

it('reports a freshly created private channel as private without a refresh', function () {
    [$workspace] = makeWorkspaceWithOwner();

    // The database column is generated during INSERT and not returned, so a
    // model that has never been reloaded must still answer correctly.
    $channel = makeChannel($workspace, ChannelType::Private);

    expect($channel->is_private)->toBeTrue();
});

it('derives is_private from the channel type in the database', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $public = makeChannel($workspace, ChannelType::Public);
    $private = makeChannel($workspace, ChannelType::Private);
    $announcement = makeChannel($workspace, ChannelType::Announcement);

    expect($public->fresh()->is_private)->toBeFalse()
        ->and($private->fresh()->is_private)->toBeTrue()
        ->and($announcement->fresh()->is_private)->toBeFalse();
});
