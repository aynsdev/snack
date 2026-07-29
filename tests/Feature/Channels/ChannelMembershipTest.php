<?php

use App\Actions\Channels\JoinChannel;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;

it('lets a member join a public channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($member)
        ->post(route('channels.membership.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

it('refuses to let a member join a private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($member)
        ->post(route('channels.membership.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertNotFound();

    expect($channel->fresh()->hasMember($member))->toBeFalse();
});

it('refuses to let a guest join a channel unprompted', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $channel = makeChannel($workspace);

    $this->actingAs($guest)
        ->post(route('channels.membership.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertNotFound();
});

it('honours the workspace setting that disables self-serve joins', function () {
    $workspace = makeWorkspace([
        'settings' => ['channels' => ['allow_self_serve_joins' => false]],
    ]);
    joinWorkspace($workspace, $workspace->owner, WorkspaceRole::Owner);

    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($member)
        ->post(route('channels.membership.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertForbidden();
});

it('is idempotent when joining a channel twice', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    addToChannel($channel, $member);

    app(JoinChannel::class)->handle($channel, $member);

    expect($channel->members()->where('user_id', $member->id)->count())->toBe(1);
});

it('lets a member leave a channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->delete(route('channels.membership.destroy', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeFalse();
});

it('refuses to let a member leave a default channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, attributes: ['is_default' => true]);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->delete(route('channels.membership.destroy', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertForbidden();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});
