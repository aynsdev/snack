<?php

use App\Models\ChannelGroup;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->base = "/api/v1/w/{$this->workspace->slug}";
    $this->withToken(mobileToken($this->owner));
});

it('creates a section, filing a channel under it', function () {
    $response = $this->postJson("{$this->base}/channel-groups", ['name' => 'Team', 'channel_id' => $this->channel->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Team');

    expect($this->channel->membershipFor($this->owner)->channel_group_id)->toBe($response->json('data.id'));
});

it('refuses a duplicate section name', function () {
    ChannelGroup::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'name' => 'Team']);

    $this->postJson("{$this->base}/channel-groups", ['name' => 'team'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('renames, moves and deletes a section', function () {
    $first = ChannelGroup::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'name' => 'A', 'position' => 1]);
    $second = ChannelGroup::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'name' => 'B', 'position' => 2]);

    $this->patchJson("{$this->base}/channel-groups/{$first->id}", ['name' => 'Alpha'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Alpha');

    $this->postJson("{$this->base}/channel-groups/{$second->id}/move", ['direction' => 'up'])
        ->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id);

    $this->deleteJson("{$this->base}/channel-groups/{$first->id}")->assertNoContent();

    expect(ChannelGroup::find($first->id))->toBeNull();
});

it('hides somebody else\'s section', function () {
    $other = joinWorkspace($this->workspace);
    $theirs = ChannelGroup::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $other->id]);

    $this->patchJson("{$this->base}/channel-groups/{$theirs->id}", ['name' => 'Mine'])->assertNotFound();
    $this->deleteJson("{$this->base}/channel-groups/{$theirs->id}")->assertNotFound();
});

it('files a channel under a section and back out', function () {
    $group = ChannelGroup::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);

    $this->patchJson("{$this->base}/channels/{$this->channel->slug}/group", ['channel_group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('data.channel_group_id', $group->id);

    $this->patchJson("{$this->base}/channels/{$this->channel->slug}/group", ['channel_group_id' => null])
        ->assertOk()
        ->assertJsonPath('data.channel_group_id', null);
});
