<?php

use App\Enums\NotificationReason;
use App\Enums\PresenceStatus;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\DailyDigestNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The daily catch-up email.
 *
 * The command runs hourly and picks whoever has just reached their own digest
 * hour, so these use `--force` to take the clock out of the assertions wherever
 * the clock is not the thing being tested.
 */

/**
 * An opted-in member who is away, with `$count` unread notifications waiting.
 *
 * @return array{0: Workspace, 1: User}
 */
function digestSubscriber(int $count = 1, ?string $timezone = null): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill([
        'presence_status' => PresenceStatus::Offline,
        'timezone' => $timezone ?? 'UTC',
    ])->save();

    $membership = $owner->membershipIn($workspace);
    $membership->forceFill(['notification_preferences' => ['digest' => ['enabled' => true]]])->save();

    for ($i = 0; $i < $count; $i++) {
        NotificationRecord::query()->create([
            'id' => (string) str()->uuid(),
            'type' => NotificationReason::Mention->value,
            'notifiable_type' => $owner->getMorphClass(),
            'notifiable_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'data' => [
                'preview' => "something happened {$i}",
                'actor' => ['name' => 'Ada Lovelace'],
                'container' => ['kind' => 'channel', 'slug' => 'design'],
            ],
        ]);
    }

    return [$workspace, $owner->fresh()];
}

it('emails an opted-in member what they missed', function () {
    Notification::fake();

    [, $owner] = digestSubscriber(count: 3);

    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertSentTo($owner, DailyDigestNotification::class);
});

it('sends nothing to a member who has not opted in', function () {
    Notification::fake();

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $owner->forceFill(['presence_status' => PresenceStatus::Offline])->save();

    NotificationRecord::query()->create([
        'id' => (string) str()->uuid(),
        'type' => NotificationReason::Mention->value,
        'notifiable_type' => $owner->getMorphClass(),
        'notifiable_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'data' => ['preview' => 'unread'],
    ]);

    // Off by default: a summary nobody asked for is how an application gets
    // filed under promotions.
    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('sends nothing when there is nothing unread', function () {
    Notification::fake();

    [$workspace, $owner] = digestSubscriber();

    NotificationRecord::query()
        ->forWorkspace($workspace, $owner)
        ->update(['read_at' => now()]);

    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips a member who has been reading all morning', function () {
    Notification::fake();

    [, $owner] = digestSubscriber();

    $owner->forceFill([
        'presence_status' => PresenceStatus::Online,
        'last_seen_at' => now(),
    ])->save();

    // Telling somebody what they have just read is not a catch-up.
    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('leaves out anything older than the digest window', function () {
    Notification::fake();

    [$workspace, $owner] = digestSubscriber();

    NotificationRecord::query()
        ->forWorkspace($workspace, $owner)
        ->update(['created_at' => now()->subDays(3)]);

    // A summary that reaches back further is a second inbox, not a catch-up.
    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('only sends at the member\'s own digest hour', function () {
    Notification::fake();

    config(['snack.notifications.digest.hour' => 8]);

    [, $owner] = digestSubscriber(timezone: 'UTC');

    /*
     * The clock is set in the *member's* zone, not the application's.
     * `now()->setTime(...)` would read as whatever `app.timezone` happens to
     * be, so this test passed only while that was UTC and silently started
     * failing the day it was changed — the command was never wrong, it asks
     * `Carbon::now($user->timezone)` and always did.
     */
    $subscriberClock = fn (int $hour, int $minute = 0) => now()
        ->setTimezone('UTC')
        ->setTime($hour, $minute);

    // 03:00 is not 08:00 anywhere the member is, so nothing goes out — which is
    // the whole reason the command wakes hourly instead of daily.
    $this->travelTo($subscriberClock(3), function () {
        $this->artisan('snack:send-digests')->assertSuccessful();
    });

    Notification::assertNothingSent();

    $this->travelTo($subscriberClock(8, 30), function () {
        $this->artisan('snack:send-digests')->assertSuccessful();
    });

    Notification::assertSentTo($owner, DailyDigestNotification::class);
});

it('does not mark anything read by summarising it', function () {
    Notification::fake();

    [$workspace, $owner] = digestSubscriber(count: 2);

    $this->artisan('snack:send-digests', ['--force' => true])->assertSuccessful();

    // The digest tells you what is waiting; it does not clear it for you.
    expect(NotificationRecord::query()->forWorkspace($workspace, $owner)->whereNull('read_at')->count())
        ->toBe(2);
});
