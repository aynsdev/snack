<?php

use App\Events\HuddleActivity;
use App\Events\HuddleChanged;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use Illuminate\Support\Facades\Event;

function joinChannelHuddle($workspace, $channel): string
{
    return route('channels.huddle.store', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]);
}

it('starts a huddle in a channel and puts the caller in it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $response = $this->actingAs($owner)
        ->postJson(joinChannelHuddle($workspace, $channel))
        ->assertOk();

    $huddle = Huddle::query()->live()->forContainer($channel)->firstOrFail();

    expect($huddle->started_by)->toBe($owner->id)
        ->and($huddle->hasActiveParticipant($owner))->toBeTrue();

    $response->assertJsonPath('huddle.id', $huddle->public_id)
        ->assertJsonPath('huddle.is_live', true)
        ->assertJsonPath('huddle.container.kind', 'channel')
        ->assertJsonPath('huddle.container.id', $channel->id)
        ->assertJsonPath('huddle.participants.0.id', $owner->id);
});

/**
 * The credential is the whole point of the endpoint, and it must never appear in
 * the shape that gets broadcast to the channel.
 */
it('answers with a connection the browser can dial', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)
        ->postJson(joinChannelHuddle($workspace, $channel))
        ->assertOk()
        ->assertJsonPath('connection.url', 'ws://localhost:7880')
        ->assertJsonPath('connection.identity', 'user.'.$owner->id)
        ->assertJsonStructure(['connection' => ['url', 'token', 'identity']]);
});

it('walks a second person into the huddle that is already running', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();
    $this->actingAs($other)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    // One huddle, two people — not two huddles, which is what a lost race
    // would produce and which would leave each of them alone in a room.
    expect(Huddle::query()->forContainer($channel)->count())->toBe(1);

    $huddle = Huddle::query()->live()->forContainer($channel)->firstOrFail();

    expect($huddle->activeParticipants()->count())->toBe(2)
        ->and($huddle->started_by)->toBe($owner->id);
});

/**
 * A double-clicked Join, or a client re-announcing itself after a reconnect.
 * Guarded by the partial unique index rather than by a check-then-insert.
 */
it('is idempotent when somebody already in the huddle asks again', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();
    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    $huddle = Huddle::query()->live()->forContainer($channel)->firstOrFail();

    expect($huddle->activeParticipants()->count())->toBe(1)
        ->and(HuddleParticipant::query()->where('huddle_id', $huddle->id)->count())->toBe(1);
});

/**
 * One huddle at a time per person. LiveKit disconnects a duplicate identity at
 * the media layer, so a roster that still listed them in the first call would be
 * describing a connection that no longer exists.
 */
it('takes somebody out of their previous huddle when they join another', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $first = makeChannel($workspace, attributes: ['name' => 'first']);
    $second = makeChannel($workspace, attributes: ['name' => 'second']);
    addToChannel($first, $owner);
    addToChannel($second, $owner);

    // Somebody else holds the first huddle open, so it is not simply ended by
    // the departure — the assertion is about the roster, not the lifecycle.
    $companion = joinWorkspace($workspace);
    addToChannel($first, $companion);

    $this->actingAs($companion)->postJson(joinChannelHuddle($workspace, $first))->assertOk();
    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $first))->assertOk();
    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $second))->assertOk();

    $firstHuddle = Huddle::query()->live()->forContainer($first)->firstOrFail();
    $secondHuddle = Huddle::query()->live()->forContainer($second)->firstOrFail();

    expect($firstHuddle->hasActiveParticipant($owner))->toBeFalse()
        ->and($firstHuddle->hasActiveParticipant($companion))->toBeTrue()
        ->and($secondHuddle->hasActiveParticipant($owner))->toBeTrue();
});

it('refuses to admit anyone once the huddle is full', function () {
    config()->set('snack.huddles.max_participants', 1);

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    $this->actingAs($other)
        ->postJson(joinChannelHuddle($workspace, $channel))
        ->assertForbidden();

    $huddle = Huddle::query()->live()->forContainer($channel)->firstOrFail();

    expect($huddle->activeParticipants()->count())->toBe(1);
});

/**
 * Somebody reconnecting must never be refused because the room they are already
 * in is full — the most infuriating possible reading of a capacity limit.
 */
it('lets somebody already in a full huddle reconnect', function () {
    config()->set('snack.huddles.max_participants', 1);

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();
    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();
});

it('announces a started huddle to the container and to its members', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $member = joinWorkspace($workspace);
    addToChannel($channel, $member);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    Event::assertDispatched(HuddleChanged::class);

    // The sidebar signal reaches the *other* member and not the person who
    // started it — their own client already knows.
    Event::assertDispatched(
        HuddleActivity::class,
        fn (HuddleActivity $event): bool => $event->isLive
            && $event->recipientIds === [$member->id],
    );
});

/**
 * The mobile app's incoming-call screen is driven by this event, and it has to
 * say who is calling without a second request.
 */
it('names who started the huddle on the sidebar signal', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$owner, $other]);

    $this->actingAs($owner)
        ->postJson(route('conversations.huddle.store', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertOk();

    Event::assertDispatched(
        HuddleActivity::class,
        fn (HuddleActivity $event): bool => $event->recipientIds === [$other->id]
            && $event->broadcastWith()['is_live'] === true
            && $event->broadcastWith()['container'] === ['kind' => 'conversation', 'id' => $conversation->public_id]
            && $event->broadcastWith()['started_by'] === [
                'id' => $owner->id,
                'name' => $owner->name,
                'avatar_url' => $owner->avatarUrl(),
            ],
    );
});

/**
 * Joining an existing huddle moves the roster, which the huddle bar renders —
 * but it is not news to anybody looking at another channel.
 */
it('does not raise the sidebar signal when somebody merely joins', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $this->actingAs($owner)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    Event::fake([HuddleActivity::class, HuddleChanged::class]);

    $this->actingAs($other)->postJson(joinChannelHuddle($workspace, $channel))->assertOk();

    Event::assertDispatched(HuddleChanged::class);
    Event::assertNotDispatched(HuddleActivity::class);
});

it('starts a huddle in a direct conversation', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $other]);

    $this->actingAs($owner)
        ->postJson(route('conversations.huddle.store', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertOk()
        ->assertJsonPath('huddle.container.kind', 'conversation')
        ->assertJsonPath('huddle.container.id', $conversation->public_id);

    expect(Huddle::query()->live()->forContainer($conversation)->exists())->toBeTrue();
});
