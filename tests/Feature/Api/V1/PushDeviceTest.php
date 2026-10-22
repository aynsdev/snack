<?php

use App\Enums\WorkspaceRole;
use App\Models\PushDevice;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('registers a phone for push', function () {
    $this->withToken(mobileToken($this->user))
        ->postJson('/api/v1/push-devices', ['token' => 'fcm-token-1', 'platform' => 'android', 'device_name' => 'Pixel 9'])
        ->assertCreated()
        ->assertJsonPath('data.platform', 'android');

    $device = PushDevice::sole();

    expect($device->user_id)->toBe($this->user->id)
        ->and($device->oauth_access_token_id)->not->toBeNull();
});

it('treats registering the same token again as a refresh', function () {
    $this->withToken(mobileToken($this->user));

    $this->postJson('/api/v1/push-devices', ['token' => 'fcm-token-1', 'platform' => 'ios'])->assertCreated();
    $this->postJson('/api/v1/push-devices', ['token' => 'fcm-token-1', 'platform' => 'ios'])->assertOk();

    expect(PushDevice::count())->toBe(1);
});

it('moves a token to whoever signs in on the phone next', function () {
    PushDevice::factory()->for(User::factory())->create(['token' => 'shared-phone']);

    $this->withToken(mobileToken($this->user))
        ->postJson('/api/v1/push-devices', ['token' => 'shared-phone', 'platform' => 'android'])
        ->assertOk();

    expect(PushDevice::sole()->user_id)->toBe($this->user->id);
});

it('validates the platform', function () {
    $this->withToken(mobileToken($this->user))
        ->postJson('/api/v1/push-devices', ['token' => 'x', 'platform' => 'windows-phone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');
});

it('unregisters only the caller\'s own token', function () {
    $mine = PushDevice::factory()->for($this->user)->create();
    $theirs = PushDevice::factory()->create();

    $this->withToken(mobileToken($this->user))
        ->deleteJson('/api/v1/push-devices', ['token' => $theirs->token])
        ->assertNoContent();

    expect(PushDevice::find($theirs->id))->not->toBeNull();

    $this->deleteJson('/api/v1/push-devices', ['token' => $mine->token])->assertNoContent();

    expect(PushDevice::find($mine->id))->toBeNull();
});

it('stops pushing to a phone when it signs out', function () {
    $phone = mobileToken($this->user);
    $tablet = mobileToken($this->user);

    $this->withToken($tablet)->postJson('/api/v1/push-devices', ['token' => 'tablet-token', 'platform' => 'android'])->assertCreated();
    app('auth')->forgetGuards();
    $this->withToken($phone)->postJson('/api/v1/push-devices', ['token' => 'phone-token', 'platform' => 'ios'])->assertCreated();

    $this->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(PushDevice::pluck('token')->all())->toBe(['tablet-token']);
});

it('saves the push preference from the app and leaves it alone from the web form', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $this->withToken(mobileToken($member))
        ->patchJson("/api/v1/w/{$workspace->slug}/notification-preferences", [
            'default_level' => 'mentions',
            'email_enabled' => true,
            'digest_enabled' => false,
            'push_enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.preferences.push_enabled', false);

    $this->actingAs($member, 'web')
        ->patch(route('notification-preferences.update', $workspace), [
            'default_level' => 'mentions',
            'email_enabled' => false,
            'digest_enabled' => false,
        ]);

    expect($member->membershipIn($workspace)->refresh()->wantsPushNotifications())->toBeFalse()
        ->and($member->membershipIn($workspace)->wantsEmailNotifications())->toBeFalse();
});
