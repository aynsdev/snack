<?php

use App\Events\HuddleActivity;
use App\Events\HuddleChanged;
use App\Models\Huddle;
use Illuminate\Support\Facades\Event;

function leaveRoute($workspace, $channel): string
{
    return route('channels.huddle.destroy', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]);
}

it('takes somebody out of the huddle without ending it for everyone else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $huddle = makeHuddle($channel, [$owner, $other]);

    $this->actingAs($owner)
        ->deleteJson(leaveRoute($workspace, $channel))
        ->assertOk()
        ->assertJsonPath('huddle', null);

    $huddle->refresh();

    expect($huddle->isLive())->toBeTrue()
        ->and($huddle->hasActiveParticipant($owner))->toBeFalse()
        ->and($huddle->hasActiveParticipant($other))->toBeTrue();
});

/**
 * A channel advertising a call with nobody in it is worse than one advertising
 * none at all, because people click it.
 */
it('ends the huddle when the last person leaves', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);

    $this->actingAs($owner)->deleteJson(leaveRoute($workspace, $channel))->assertOk();

    expect($huddle->fresh()->isLive())->toBeFalse()
        ->and(Huddle::query()->live()->forContainer($channel)->exists())->toBeFalse();
});

/**
 * Hanging up is announced by a button, by the page unloading, and by the tab
 * closing. All three can fire for one departure.
 */
it('is idempotent and stays quiet on a repeat hang-up', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeHuddle($channel, [$owner]);

    $this->actingAs($owner)->deleteJson(leaveRoute($workspace, $channel))->assertOk();

    Event::fake([HuddleChanged::class, HuddleActivity::class]);

    // Nothing live left to leave: a 200 with nothing to say, and — importantly
    // — no second broadcast, which is what makes a huddle bar flicker.
    $this->actingAs($owner)
        ->deleteJson(leaveRoute($workspace, $channel))
        ->assertOk()
        ->assertJsonPath('huddle', null);

    Event::assertNotDispatched(HuddleChanged::class);
    Event::assertNotDispatched(HuddleActivity::class);
});

it('announces the end of a huddle to the container members', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeHuddle($channel, [$owner]);

    $this->actingAs($owner)->deleteJson(leaveRoute($workspace, $channel))->assertOk();

    Event::assertDispatched(
        HuddleActivity::class,
        fn (HuddleActivity $event): bool => $event->isLive === false
            && $event->broadcastWith()['started_by'] === null,
    );
});

/**
 * Getting *out* of a call has to work even for somebody whose right to be in it
 * has just been revoked — which is why the policy asks only for read access.
 */
it('lets somebody leave a huddle in a channel that was archived under them', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeHuddle($channel, [$owner]);

    $channel->update(['is_archived' => true]);

    $this->actingAs($owner)->deleteJson(leaveRoute($workspace, $channel))->assertOk();

    expect(Huddle::query()->live()->forContainer($channel)->exists())->toBeFalse();
});

it('closes an abandoned huddle from the sweep', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);

    /*
     * A crashed tab: the participant row is closed but the leave request never
     * arrived, so nothing ended the huddle. Backdated past the grace period,
     * which exists so a page reload does not tear down a live call.
     */
    $stale = now()->subMinutes(10);

    // `joined_at` moves with it: huddle_participants_ends_after_join_check
    // refuses a visit that ended before it began, which is what a fixture that
    // backdated only the departure would be describing.
    $huddle->participants()->update(['joined_at' => $stale, 'left_at' => $stale]);
    $huddle->forceFill(['started_at' => $stale])->save();

    $this->artisan('snack:sweep-huddles')->assertSuccessful();

    expect($huddle->fresh()->isLive())->toBeFalse();

    Event::assertDispatched(HuddleChanged::class);
});

/**
 * A huddle whose only participant is reloading the page is briefly empty in
 * perfectly healthy circumstances. Closing on sight would tear down a call
 * somebody is in the middle of rejoining.
 */
it('leaves a briefly empty huddle alone', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);

    $huddle->participants()->update(['left_at' => now()]);
    $huddle->forceFill(['started_at' => now()->subMinutes(10)])->save();

    $this->artisan('snack:sweep-huddles')->assertSuccessful();

    expect($huddle->fresh()->isLive())->toBeTrue();
});

it('leaves a huddle that still has somebody in it alone', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);
    $huddle->forceFill(['started_at' => now()->subHour()])->save();

    $this->artisan('snack:sweep-huddles')->assertSuccessful();

    expect($huddle->fresh()->isLive())->toBeTrue();
});
