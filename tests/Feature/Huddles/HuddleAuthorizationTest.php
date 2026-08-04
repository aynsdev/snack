<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Huddle;

function huddleRoute($workspace, $channel): string
{
    return route('channels.huddle.store', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]);
}

/**
 * A private channel must not confirm its own existence to somebody who was never
 * invited to it — the same 404 ChannelPolicy::view gives them. Delegating to
 * `post` is what keeps the two answers in step.
 */
it('hides a private channel huddle from a non-member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($outsider)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertNotFound();

    expect(Huddle::query()->forContainer($channel)->exists())->toBeFalse();
});

/**
 * Joining puts your voice in the room, so it is held to the same standard as
 * posting — someone previewing a public channel they have not joined can see a
 * huddle is happening and cannot walk into it.
 */
it('refuses somebody who is only previewing a public channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($member)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertForbidden();
});

it('refuses to hold a huddle in an archived channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace, attributes: ['is_archived' => true]);
    addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertForbidden();
});

/**
 * An announcement channel restricts posting to workspace admins, and a huddle is
 * a contribution — so it inherits that restriction without restating it.
 */
it('applies the announcement channel posting rule to huddles', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace, ChannelType::Announcement);

    $member = joinWorkspace($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertForbidden();
});

it('refuses a guest who was never added to the channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $channel = makeChannel($workspace);

    $this->actingAs($guest)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertNotFound();
});

/**
 * A deployment with no media server should refuse at the door rather than hand
 * out a token for a server that is not there.
 */
it('refuses when the deployment has no media server', function () {
    config()->set('snack.huddles.enabled', false);

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertForbidden();

    expect(Huddle::query()->forContainer($channel)->exists())->toBeFalse();
});

it('refuses when LiveKit credentials are missing', function () {
    config()->set('services.livekit.secret', null);

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->postJson(huddleRoute($workspace, $channel))
        ->assertForbidden();
});

it('keeps somebody out of a conversation they do not participate in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);
    $outsider = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $other]);

    $this->actingAs($outsider)
        ->postJson(route('conversations.huddle.store', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertNotFound();
});

/**
 * The Huddle control renders off a single server-computed flag, so the client
 * never reimplements the rule. If this drifts, the button appears for people the
 * endpoint will refuse.
 */
it('tells the channel page whether the viewer may huddle', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->get(route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canHuddle', true)
            ->where('huddle', null),
        );
});

it('ships a live huddle with the channel page', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);

    $this->actingAs($owner)
        ->get(route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('huddle.id', $huddle->public_id)
            ->where('huddle.is_live', true)
            ->count('huddle.participants', 1),
        );
});
