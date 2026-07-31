<?php

use App\Actions\Channels\JoinChannel;
use App\Enums\ChannelType;
use App\Enums\NotificationLevel;
use App\Models\ChannelMember;
use App\Models\User;

/**
 * The settings a member keeps for one workspace: their defaults, and the
 * per-channel and per-conversation overrides they have made.
 */
it('shows a member their own defaults', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->get(route('notification-preferences.edit', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications/preferences')
            // Mentions is the fallback when nothing has been set: notifying on
            // every message would be overwhelming once a workspace grows.
            ->where('preferences.default_level', NotificationLevel::Mentions->value)
            ->where('preferences.email_enabled', true)
            ->where('preferences.digest_enabled', false)
            ->where('preferences.do_not_disturb', null));
});

it('saves the workspace defaults', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->from(route('notification-preferences.edit', ['workspace' => $workspace->slug]))
        ->patch(route('notification-preferences.update', ['workspace' => $workspace->slug]), [
            'default_level' => NotificationLevel::All->value,
            'email_enabled' => false,
            'digest_enabled' => true,
            'do_not_disturb' => ['start' => '22:00', 'end' => '07:00'],
        ])
        ->assertRedirect();

    $membership = $owner->membershipIn($workspace)->fresh();

    expect($membership->defaultNotificationLevel())->toBe(NotificationLevel::All)
        ->and($membership->wantsEmailNotifications())->toBeFalse()
        ->and($membership->wantsDailyDigest())->toBeTrue()
        ->and($membership->doNotDisturbSchedule())->toBe(['start' => '22:00', 'end' => '07:00']);
});

it('treats a zero-length quiet-hours window as turning it off', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    // What a form leaves behind when the fields are cleared. Read literally it
    // would silence nothing while still looking set.
    $this->actingAs($owner)
        ->patch(route('notification-preferences.update', ['workspace' => $workspace->slug]), [
            'default_level' => NotificationLevel::Mentions->value,
            'email_enabled' => true,
            'digest_enabled' => false,
            'do_not_disturb' => ['start' => '09:00', 'end' => '09:00'],
        ]);

    expect($owner->membershipIn($workspace)->fresh()->doNotDisturbSchedule())->toBeNull();
});

it('rejects a quiet-hours window that is not a time', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->from(route('notification-preferences.edit', ['workspace' => $workspace->slug]))
        ->patch(route('notification-preferences.update', ['workspace' => $workspace->slug]), [
            'default_level' => NotificationLevel::Mentions->value,
            'email_enabled' => true,
            'digest_enabled' => false,
            'do_not_disturb' => ['start' => 'lunchtime', 'end' => '07:00'],
        ])
        ->assertSessionHasErrors('do_not_disturb.start');
});

it('seeds a newly joined channel from the member default', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $membership = $member->membershipIn($workspace);
    $membership->forceFill(['notification_preferences' => ['default_level' => 'all']])->save();
    $member->unsetRelation('workspaceMemberships');

    $channel = makeChannel($workspace);

    expect(app(JoinChannel::class)->handle($channel, $member)->notification_level)
        ->toBe(NotificationLevel::All);
});

it('starts an announcement channel loud whatever the member default says', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $membership = $member->membershipIn($workspace);
    $membership->forceFill(['notification_preferences' => ['default_level' => 'nothing']])->save();
    $member->unsetRelation('workspaceMemberships');

    $channel = makeChannel($workspace, ChannelType::Announcement);

    /*
     * The channel type exists to say "this is the one you should not miss", and
     * a workspace-wide "nothing" would quietly defeat it.
     */
    expect(app(JoinChannel::class)->handle($channel, $member)->notification_level)
        ->toBe(NotificationLevel::All);
});

it('leaves existing memberships alone when the default changes', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);
    $membership->forceFill(['notification_level' => NotificationLevel::Nothing])->save();

    $this->actingAs($owner)
        ->patch(route('notification-preferences.update', ['workspace' => $workspace->slug]), [
            'default_level' => NotificationLevel::All->value,
            'email_enabled' => true,
            'digest_enabled' => false,
        ]);

    // Somebody who silenced three channels last month did not ask for that to be
    // undone by touching a default.
    expect($membership->fresh()->notification_level)->toBe(NotificationLevel::Nothing);
});

it('mutes a channel for one member and nobody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    $ownerMembership = addToChannel($channel, $owner);
    addToChannel($channel, $member);

    $this->actingAs($owner)
        ->from(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $channel->slug]))
        ->patch(route('channels.preferences.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['is_muted' => true])
        ->assertRedirect();

    expect($ownerMembership->fresh()->is_muted)->toBeTrue()
        ->and(ChannelMember::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $member->id)
            ->value('is_muted'))->toBeFalse();
});

it('changes a channel level without resetting its mute', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);
    $membership->forceFill(['is_muted' => true])->save();

    // Both fields are optional so a request that sets one does not silently
    // clear the other.
    $this->actingAs($owner)
        ->from(route('channels.show', ['workspace' => $workspace->slug, 'channel' => $channel->slug]))
        ->patch(route('channels.preferences.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['notification_level' => NotificationLevel::Nothing->value]);

    $fresh = $membership->fresh();

    expect($fresh->notification_level)->toBe(NotificationLevel::Nothing)
        ->and($fresh->is_muted)->toBeTrue();
});

it('refuses to change preferences for a channel the member cannot see', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);

    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);

    $this->actingAs($outsider)
        ->patch(route('channels.preferences.update', [
            'workspace' => $workspace->slug,
            'channel' => $private->slug,
        ]), ['is_muted' => true])
        ->assertNotFound();
});

it('lists only the channels a member has actually overridden', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $untouched = makeChannel($workspace, attributes: ['name' => 'general']);
    addToChannel($untouched, $owner);

    $silenced = makeChannel($workspace, attributes: ['name' => 'random']);
    addToChannel($silenced, $owner)
        ->forceFill(['notification_level' => NotificationLevel::Nothing])
        ->save();

    // A row per joined channel would bury the handful that were changed under
    // the ones that were not.
    $this->actingAs($owner)
        ->get(route('notification-preferences.edit', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page
            ->has('channels', 1)
            ->where('channels.0.slug', $silenced->slug));
});

it('keeps a non-member out of the preferences screen', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $this->actingAs(User::factory()->create())
        ->get(route('notification-preferences.edit', ['workspace' => $workspace->slug]))
        ->assertNotFound();
});
