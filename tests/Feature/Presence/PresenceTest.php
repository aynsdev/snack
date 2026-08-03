<?php

use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\User;
use App\Services\NotificationDelivery;
use Illuminate\Support\Facades\Event;

/**
 * Presence: the green dot, and everything that quietly depends on it.
 *
 * `users.presence_status` was read in three places long before anything wrote
 * it — the avatar, `@here`, and {@see NotificationDelivery} — so
 * every account sat at the column default of Offline forever. That is not only
 * a wrong dot: an Offline user is by definition away, so every notification was
 * also emailed, and `@here` expanded to nobody. These tests cover the writer.
 *
 * The through-line is that presence is a *claim with an expiry*, never a flag.
 * A browser that crashes or loses its network says nothing at all, so anything
 * that can only be set and never decays is wrong by construction.
 */
it('marks a heartbeat sender online', function () {
    [, $owner] = makeWorkspaceWithOwner();

    // The column default, and — until there was a writer — the only value any
    // real account ever held.
    expect($owner->presence_status)->toBe(PresenceStatus::Offline);

    $this->actingAs($owner)
        ->postJson(route('presence.store'), ['status' => 'online'])
        ->assertOk()
        ->assertJson(['presence_status' => 'online']);

    $owner->refresh();

    expect($owner->presence_status)->toBe(PresenceStatus::Online)
        ->and($owner->last_seen_at)->not->toBeNull()
        /*
         * Not merely non-null: `last_seen_at` is a timestamptz, and a mismatch
         * between the Postgres session timezone and app.timezone stored it
         * hours from the instant PHP meant. Read back in the future, it made
         * "has it been long enough" permanently false — so the sweep never
         * demoted anyone and no digest could ever go out.
         */
        ->and($owner->last_seen_at->diffInMinutes(now(), absolute: true))->toBeLessThan(1);
});

it('accepts a hidden tab reporting itself away', function () {
    [, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->postJson(route('presence.store'), ['status' => 'away'])
        ->assertOk();

    expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Away);
});

it('refuses a client claiming to be offline or do-not-disturb', function (string $status) {
    [, $owner] = makeWorkspaceWithOwner();

    /*
     * Neither is the client's to assert. Offline is the absence of heartbeats,
     * arrived at by the sweep; do-not-disturb is a deliberate setting, and a
     * background timer must not be able to switch it on for somebody.
     */
    $this->actingAs($owner)
        ->postJson(route('presence.store'), ['status' => $status])
        ->assertInvalid('status');
})->with(['offline', 'do_not_disturb']);

it('advances last_seen_at for a do-not-disturb user without unsetting it', function () {
    [, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill([
        'presence_status' => PresenceStatus::DoNotDisturb,
        'last_seen_at' => now()->subHour(),
    ])->save();

    $this->actingAs($owner)
        ->postJson(route('presence.store'), ['status' => 'online'])
        ->assertOk();

    $owner->refresh();

    // Present, and still not disturbable — a heartbeat is evidence of life, not
    // a vote on how somebody wants to be treated.
    expect($owner->presence_status)->toBe(PresenceStatus::DoNotDisturb)
        ->and($owner->last_seen_at->diffInMinutes(now(), absolute: true))->toBeLessThan(1);
});

it('takes presence back on sign-out', function () {
    [, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)->postJson(route('presence.store'))->assertOk();

    expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Online);

    $this->post(route('logout'));

    expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Offline);
});

it('requires a signed-in user', function () {
    $this->postJson(route('presence.store'))->assertUnauthorized();
});

it('announces a change once, and says nothing on an unchanged beat', function () {
    Event::fake([PresenceChanged::class]);

    [, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)->postJson(route('presence.store'))->assertOk();

    Event::assertDispatchedTimes(PresenceChanged::class, 1);

    // A second beat says the same thing. Broadcasting it would put a
    // workspace-wide fan-out on a one-minute timer to announce nothing.
    $this->postJson(route('presence.store'))->assertOk();

    Event::assertDispatchedTimes(PresenceChanged::class, 1);
});

it('broadcasts a change only to workspaces the user is in', function () {
    Event::fake([PresenceChanged::class]);

    [$mine, $owner] = makeWorkspaceWithOwner();
    [$theirs] = makeWorkspaceWithOwner();

    $this->actingAs($owner)->postJson(route('presence.store'))->assertOk();

    Event::assertDispatched(
        PresenceChanged::class,
        fn (PresenceChanged $event): bool => $event->workspaceIds === [$mine->id]
            && ! in_array($theirs->id, $event->workspaceIds, true),
    );
});

describe('the sweep', function () {
    it('demotes a quiet tab to away, then to offline', function () {
        [, $owner] = makeWorkspaceWithOwner();

        $owner->forceFill([
            'presence_status' => PresenceStatus::Online,
            'last_seen_at' => now()->subSeconds(200),
        ])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Away);

        /*
         * The regression this exists for: the first step must not stamp
         * `last_seen_at`. If it did, it would restart the clock the second step
         * measures against and this user would sit at Away forever — which
         * looks plausible enough on screen that nobody would report it.
         */
        $owner->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Offline);
    });

    it('leaves a recently seen user alone', function () {
        [, $owner] = makeWorkspaceWithOwner();

        $owner->forceFill([
            'presence_status' => PresenceStatus::Online,
            'last_seen_at' => now()->subSeconds(30),
        ])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Online);
    });

    it('does not demote do-not-disturb to away, but does take it offline', function () {
        [, $owner] = makeWorkspaceWithOwner();

        $owner->forceFill([
            'presence_status' => PresenceStatus::DoNotDisturb,
            'last_seen_at' => now()->subSeconds(200),
        ])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        // Idle is a guess about attention; do-not-disturb is a stated choice.
        expect($owner->refresh()->presence_status)->toBe(PresenceStatus::DoNotDisturb);

        $owner->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        // A closed browser is gone whatever it last chose to display.
        expect($owner->refresh()->presence_status)->toBe(PresenceStatus::Offline);
    });

    it('ignores users who have never been seen at all', function () {
        [, $owner] = makeWorkspaceWithOwner();

        $owner->forceFill([
            'presence_status' => PresenceStatus::Offline,
            'last_seen_at' => null,
        ])->save();

        $this->artisan('snack:sweep-presence')->assertSuccessful();

        expect(User::query()->whereKey($owner->id)->value('last_seen_at'))->toBeNull();
    });
});
