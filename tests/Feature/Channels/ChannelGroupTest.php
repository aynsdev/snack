<?php

use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Support\Positions;

it('creates a section and puts it at the end of the list', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->post(route('channel-groups.store', $workspace->slug), ['name' => 'Team'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('channel-groups.store', $workspace->slug), ['name' => 'Noise'])
        ->assertRedirect();

    // Order of creation, not the alphabet: the point of a position column is
    // that the person decides.
    expect(
        ChannelGroup::query()->ownedBy($workspace, $owner)->ordered()->pluck('name')->all(),
    )->toBe(['Team', 'Noise']);
});

it('refuses a second section with the same name, whatever its case', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Team',
    ]);

    $this->actingAs($owner)
        ->post(route('channel-groups.store', $workspace->slug), ['name' => 'team'])
        ->assertSessionHasErrors('name');

    expect(ChannelGroup::query()->ownedBy($workspace, $owner)->count())->toBe(1);
});

it('lets two people in one workspace use the same section name', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Team',
    ]);

    // Uniqueness is per person, not per workspace: these are private sidebars,
    // and neither of them has any business being told the other took a name.
    $this->actingAs($other)
        ->post(route('channel-groups.store', $workspace->slug), ['name' => 'Team'])
        ->assertSessionHasNoErrors();

    expect(ChannelGroup::query()->ownedBy($workspace, $other)->count())->toBe(1);
});

it('lets a guest arrange the channels they were added to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    // Unlike project groups, which a guest may not make: a section holds no
    // channels of its own and grants access to none, and the handful of rooms
    // a guest is in is still a list they can tidy.
    $this->actingAs($guest)
        ->post(route('channel-groups.store', $workspace->slug), ['name' => 'Mine'])
        ->assertSessionHasNoErrors();

    expect(ChannelGroup::query()->ownedBy($workspace, $guest)->count())->toBe(1);
});

it('renames a section', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $group = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Team',
    ]);

    $this->actingAs($owner)
        ->patch(route('channel-groups.update', [$workspace->slug, $group->id]), ['name' => 'Squad'])
        ->assertRedirect();

    expect($group->refresh()->name)->toBe('Squad');
});

it('hides another member section behind a 404', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $theirs = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $other->id,
        'name' => 'Theirs',
    ]);

    // Not "forbidden": that would confirm this person has a section with this
    // id, which is a fact about their sidebar and not ours to hand out.
    $this->actingAs($owner)
        ->patch(route('channel-groups.update', [$workspace->slug, $theirs->id]), ['name' => 'Mine now'])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('channel-groups.destroy', [$workspace->slug, $theirs->id]))
        ->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs');
});

it('will not let one workspace address another workspace section', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$other] = makeWorkspaceWithOwner();

    $foreign = ChannelGroup::factory()->create([
        'workspace_id' => $other->id,
        'user_id' => $owner->id,
        'name' => 'Elsewhere',
    ]);

    // Owned by this person, but in a workspace this URL is not about.
    $this->actingAs($owner)
        ->patch(route('channel-groups.update', [$workspace->slug, $foreign->id]), ['name' => 'Mine now'])
        ->assertNotFound();

    expect($foreign->refresh()->name)->toBe('Elsewhere');
});

it('moves a section up and down the list', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'First',
        'position' => Positions::GAP,
    ]);

    $second = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Second',
        'position' => Positions::GAP * 2,
    ]);

    $ordered = fn (): array => ChannelGroup::query()
        ->ownedBy($workspace, $owner)
        ->ordered()
        ->pluck('name')
        ->all();

    $this->actingAs($owner)
        ->post(route('channel-groups.move', [$workspace->slug, $second->id]), ['direction' => 'up'])
        ->assertRedirect();

    expect($ordered())->toBe(['Second', 'First']);

    $this->actingAs($owner)
        ->post(route('channel-groups.move', [$workspace->slug, $second->id]), ['direction' => 'down'])
        ->assertRedirect();

    expect($ordered())->toBe(['First', 'Second']);

    // Already last: a no-op rather than an error, because the control that sent
    // it is disabled and a request that raced past it wants a state we are in.
    $this->actingAs($owner)
        ->post(route('channel-groups.move', [$workspace->slug, $second->id]), ['direction' => 'down'])
        ->assertRedirect();

    expect($ordered())->toBe(['First', 'Second']);
});

it('never swaps positions with somebody else section', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $mine = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Mine',
        'position' => Positions::GAP * 2,
    ]);

    $theirs = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $other->id,
        'name' => 'Theirs',
        'position' => Positions::GAP,
    ]);

    // Positions only mean anything within one person's list, and two people's
    // sections hold the same numbers. Moving mine up must find no neighbour.
    $this->actingAs($owner)
        ->post(route('channel-groups.move', [$workspace->slug, $mine->id]), ['direction' => 'up'])
        ->assertRedirect();

    expect($mine->refresh()->position)->toBe(Positions::GAP * 2)
        ->and($theirs->refresh()->position)->toBe(Positions::GAP);
});

it('deletes a section without removing anyone from the channels in it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $group = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);
    $membership->update(['channel_group_id' => $group->id]);

    $this->actingAs($owner)
        ->delete(route('channel-groups.destroy', [$workspace->slug, $group->id]))
        ->assertRedirect();

    expect(ChannelGroup::find($group->id))->toBeNull();

    // The heading is gone; the channel is still joined and still in the sidebar.
    expect($membership->refresh()->channel_group_id)->toBeNull()
        ->and(Channel::find($channel->id))->not->toBeNull()
        ->and($channel->hasMember($owner))->toBeTrue();
});

it('files a channel into a section and back out of one', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $group = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);

    $route = route('channels.group.update', [$workspace->slug, $channel->slug]);

    $this->actingAs($owner)
        ->patch($route, ['channel_group_id' => $group->id])
        ->assertRedirect();

    expect($membership->refresh()->channel_group_id)->toBe($group->id);

    // Null is the whole of "take it out of its section" — there is no second
    // endpoint for unfiling.
    $this->actingAs($owner)
        ->patch($route, ['channel_group_id' => null])
        ->assertRedirect();

    expect($membership->refresh()->channel_group_id)->toBeNull();
});

it('files a channel only for the person who filed it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $group = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    $channel = makeChannel($workspace);
    $mine = addToChannel($channel, $owner);
    $theirs = addToChannel($channel, $other);

    $this->actingAs($owner)
        ->patch(route('channels.group.update', [$workspace->slug, $channel->slug]), [
            'channel_group_id' => $group->id,
        ])
        ->assertRedirect();

    // The arrangement is written on the viewer's own membership row, so the
    // other member's sidebar is untouched.
    expect($mine->refresh()->channel_group_id)->toBe($group->id)
        ->and($theirs->refresh()->channel_group_id)->toBeNull();
});

it('refuses to file a channel into a section that is not yours', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $theirs = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $other->id,
    ]);

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->patch(route('channels.group.update', [$workspace->slug, $channel->slug]), [
            'channel_group_id' => $theirs->id,
        ])
        ->assertSessionHasErrors('channel_group_id');

    expect($membership->refresh()->channel_group_id)->toBeNull();
});

it('has nothing to file for someone who has not joined the channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $group = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    // Visible to them — a public channel — but not joined, so there is no
    // membership row to write and no sidebar row to arrange.
    $channel = makeChannel($workspace);

    $this->actingAs($owner)
        ->patch(route('channels.group.update', [$workspace->slug, $channel->slug]), [
            'channel_group_id' => $group->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('makes a section from a channel and files it in one request', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channel-groups.store', $workspace->slug), [
            'name' => 'Team',
            'channel_id' => $channel->id,
        ])
        ->assertRedirect();

    $group = ChannelGroup::query()->ownedBy($workspace, $owner)->firstOrFail();

    // One call, because "put this channel somewhere new" is one intention — a
    // client that made the section first would have to learn its id from a
    // redirect, and would strand an empty section when the second call failed.
    expect($membership->refresh()->channel_group_id)->toBe($group->id);
});

it('refuses to make a section from a channel the maker is not in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $channel = makeChannel($workspace);

    $this->actingAs($owner)
        ->post(route('channel-groups.store', $workspace->slug), [
            'name' => 'Team',
            'channel_id' => $channel->id,
        ])
        ->assertSessionHasErrors('channel_id');

    expect(ChannelGroup::query()->ownedBy($workspace, $owner)->count())->toBe(0);
});

it('sends the viewer own sections and filings with every workspace page', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $mine = ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Mine',
    ]);

    ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $other->id,
        'name' => 'Theirs',
    ]);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner)->update(['channel_group_id' => $mine->id]);

    $this->actingAs($owner)
        ->get(route('channels.show', [$workspace->slug, $channel->slug]))
        ->assertInertia(function ($page) use ($mine, $channel) {
            $groups = $page->toArray()['props']['snack']['current']['channel_groups'];
            $channels = $page->toArray()['props']['snack']['current']['channels'];

            expect(array_column($groups, 'name'))->toBe(['Mine'])
                ->and($channels[0]['id'])->toBe($channel->id)
                ->and($channels[0]['channel_group_id'])->toBe($mine->id);
        });
});

it('keeps an emptied section in the payload', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    ChannelGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'name' => 'Empty',
    ]);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    // The opposite of the project sidebar, which drops empty headings: this is
    // where sections are made and filled, so one that has just been emptied has
    // to stay on screen to be filled again.
    $this->actingAs($owner)
        ->get(route('channels.show', [$workspace->slug, $channel->slug]))
        ->assertInertia(function ($page) {
            $groups = $page->toArray()['props']['snack']['current']['channel_groups'];

            expect(array_column($groups, 'name'))->toBe(['Empty']);
        });
});
