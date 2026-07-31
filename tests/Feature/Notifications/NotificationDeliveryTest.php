<?php

use App\Actions\Messages\SyncMentions;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverMessageNotifications;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MessageNotification;
use App\Services\NotificationDelivery;
use Illuminate\Support\Facades\Notification;

/**
 * Whether a notification also becomes an email.
 *
 * The in-app row always gets written — that is the other half of this feature,
 * covered in NotificationCentreTest. This is only about the mail, whose rule is
 * "email people who are not here to see the banner".
 */

/**
 * A channel whose second member is in a known presence state.
 *
 * @return array{0: Workspace, 1: User, 2: User, 3: Channel}
 */
function channelWithRecipient(PresenceStatus $status, ?string $lastSeen = null): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $member->forceFill([
        'presence_status' => $status,
        'last_seen_at' => $lastSeen === null ? now() : now()->sub($lastSeen),
    ])->save();

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $member);

    return [$workspace, $owner, $member, $channel];
}

it('emails somebody who is offline', function () {
    Notification::fake();

    [, $owner, $member, $channel] = channelWithRecipient(PresenceStatus::Offline);

    $message = makeMessage($channel, $owner, "@{$member->username} are you about?");
    app(SyncMentions::class)->handle($message, $owner);

    DeliverMessageNotifications::dispatch($message->id);

    Notification::assertSentTo(
        $member,
        MessageNotification::class,
        fn (MessageNotification $notification): bool => in_array('mail', $notification->via($member), true),
    );
});

it('does not email somebody who is online and reading', function () {
    Notification::fake();

    [, $owner, $member, $channel] = channelWithRecipient(PresenceStatus::Online);

    $message = makeMessage($channel, $owner, "@{$member->username} look at this");
    app(SyncMentions::class)->handle($message, $owner);

    DeliverMessageNotifications::dispatch($message->id);

    /*
     * The banner is already on their screen. The same message arriving by email
     * ten seconds later is how an application teaches people to filter its mail.
     */
    Notification::assertSentTo(
        $member,
        MessageNotification::class,
        fn (MessageNotification $notification): bool => ! in_array('mail', $notification->via($member), true),
    );
});

it('treats a live socket that has gone idle as away', function () {
    $delivery = app(NotificationDelivery::class);

    $idle = User::factory()->create([
        'presence_status' => PresenceStatus::Online,
        'last_seen_at' => now()->subHours(3),
    ]);

    // Presence alone would call this person present and silently drop the email,
    // which is the failure the idle window exists to catch.
    expect($delivery->isAway($idle))->toBeTrue();
});

it('withholds mail from somebody on do not disturb', function () {
    $delivery = app(NotificationDelivery::class);

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill([
        'presence_status' => PresenceStatus::DoNotDisturb,
        'last_seen_at' => now()->subDay(),
    ])->save();

    expect($delivery->shouldMail($owner, $owner->membershipIn($workspace)))->toBeFalse();
});

it('withholds mail during the member\'s own quiet hours', function () {
    $delivery = app(NotificationDelivery::class);

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill([
        'timezone' => 'UTC',
        'presence_status' => PresenceStatus::Offline,
    ])->save();

    $membership = $owner->membershipIn($workspace);

    // A window that wraps midnight is the normal case, so the whole day is
    // inside it and the assertion does not depend on when the suite runs.
    $membership->forceFill([
        'notification_preferences' => ['do_not_disturb' => ['start' => '00:00', 'end' => '23:59']],
    ])->save();

    expect($delivery->shouldMail($owner->fresh(), $membership->fresh()))->toBeFalse();
});

it('respects a member who has turned email off', function () {
    $delivery = app(NotificationDelivery::class);

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill(['presence_status' => PresenceStatus::Offline])->save();

    $membership = $owner->membershipIn($workspace);
    $membership->forceFill(['notification_preferences' => ['email' => ['enabled' => false]]])->save();

    expect($delivery->shouldMail($owner->fresh(), $membership->fresh()))->toBeFalse();
});

it('still writes the in-app row for somebody on do not disturb', function () {
    [$workspace, $owner, $member, $channel] = channelWithRecipient(PresenceStatus::DoNotDisturb);

    $message = makeMessage($channel, $owner, "@{$member->username} whenever you are back");
    app(SyncMentions::class)->handle($message, $owner);

    DeliverMessageNotifications::dispatch($message->id);

    /*
     * Do not disturb suppresses interruptions, not the record. Finding an empty
     * inbox after a quiet evening would be worse than finding a full one.
     */
    expect(App\Models\Notification::query()->forWorkspace($workspace, $member)->count())->toBe(1);
});
