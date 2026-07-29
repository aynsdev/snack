<?php

use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * WebSocket subscriptions are a long-lived read grant, so they are held to the
 * same standard as the HTTP policies. A mistake here would silently hand
 * someone a live feed of a room they cannot open in the UI.
 *
 * These go through the real `/broadcasting/auth` endpoint rather than calling
 * the callbacks directly, so the channel-name parsing, the guard, and the
 * authorization callback are all exercised the way Echo exercises them.
 */
/**
 * Ask to subscribe exactly as Echo would.
 */
function subscribeAs(User $user, string $channelName): TestResponse
{
    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
        'socket_id' => '1234.5678',
    ]);
}

it('lets a workspace member subscribe to the workspace presence channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $response = subscribeAs($member, "presence-workspace.{$workspace->id}");

    $response->assertOk();

    // The presence payload is visible to every other subscriber, so it must not
    // leak anything beyond what the roster renders.
    $userInfo = json_decode($response->json('channel_data'), true)['user_info'];

    expect($userInfo)->toHaveKeys(['id', 'name', 'username'])
        ->and(array_keys($userInfo))->not->toContain('email');
});

it('refuses a workspace subscription from a non-member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = User::factory()->create();

    subscribeAs($outsider, "presence-workspace.{$workspace->id}")->assertForbidden();
});

it('refuses a workspace subscription from a suspended member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $suspended = joinWorkspace($workspace, status: MemberStatus::Suspended);

    subscribeAs($suspended, "presence-workspace.{$workspace->id}")->assertForbidden();
});

it('lets an invited member subscribe to a private channel feed', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);
    addToChannel($channel, $member);

    subscribeAs($member, "presence-channel.{$channel->id}")->assertOk();
});

it('refuses a private channel feed to a workspace member who was not invited', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    subscribeAs($member, "presence-channel.{$channel->id}")->assertForbidden();
});

it('refuses a channel feed to someone from another workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    [$otherWorkspace] = makeWorkspaceWithOwner();

    $outsider = joinWorkspace($otherWorkspace);
    $channel = makeChannel($workspace);

    subscribeAs($outsider, "presence-channel.{$channel->id}")->assertForbidden();
});

it('refuses a channel feed to a guest who was not added to it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $channel = makeChannel($workspace);

    subscribeAs($guest, "presence-channel.{$channel->id}")->assertForbidden();
});

it('lets a guest subscribe to a channel they were added to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $channel = makeChannel($workspace);
    addToChannel($channel, $guest);

    subscribeAs($guest, "presence-channel.{$channel->id}")->assertOk();
});

it('only lets a user subscribe to their own side channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    subscribeAs($user, "private-user.{$user->id}")->assertOk();
    subscribeAs($user, "private-user.{$other->id}")->assertForbidden();
});

it('only lets a user subscribe to their own notification channel', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    subscribeAs($user, "private-App.Models.User.{$user->id}")->assertOk();
    subscribeAs($user, "private-App.Models.User.{$other->id}")->assertForbidden();
});
