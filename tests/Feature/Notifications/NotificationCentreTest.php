<?php

use App\Actions\Messages\SyncMentions;
use App\Enums\NotificationLevel;
use App\Enums\NotificationReason;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverMessageNotifications;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\Notification;
use App\Models\ThreadSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;

/**
 * Who ends up with a notification, and what the centre does with it.
 *
 * The fan-out runs on a queue, so these dispatch the job directly rather than
 * going through the send endpoint — that keeps each test about one rule instead
 * of about the whole send path.
 */

/**
 * A channel with an owner and a second member, who is the one being notified.
 *
 * @return array{0: Workspace, 1: User, 2: User, 3: Channel}
 */
function notifiableChannel(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $member);

    return [$workspace, $owner, $member, $channel];
}

/**
 * Send a message the way SendMessage does — mentions materialized first, then
 * the fan-out — because the fan-out reads those rows to decide who was named.
 */
function notifyFor(Message $message, User $sender): void
{
    app(SyncMentions::class)->handle($message, $sender);

    DeliverMessageNotifications::dispatch($message->id);
}

/**
 * A notification raised in some other workspace, for the isolation assertions.
 */
function foreignNotification(Workspace $workspace, User $user): Notification
{
    return Notification::query()->create([
        'id' => (string) str()->uuid(),
        'type' => NotificationReason::Mention->value,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'workspace_id' => $workspace->id,
        'data' => ['preview' => 'from the other workspace'],
    ]);
}

it('raises a notification for the person a message names', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    notifyFor(makeMessage($channel, $owner, "hey @{$member->username} look at this"), $owner);

    $notification = Notification::query()->forWorkspace($workspace, $member)->sole();

    expect($notification->type)->toBe(NotificationReason::Mention->value)
        ->and($notification->workspace_id)->toBe($workspace->id)
        ->and($notification->isRead())->toBeFalse()
        ->and(data_get($notification->data, 'actor.name'))->toBe($owner->name)
        ->and(data_get($notification->data, 'container.slug'))->toBe($channel->slug);
});

it('never notifies the sender about their own message', function () {
    [$workspace, $owner, , $channel] = notifiableChannel();

    // Mentioning yourself happens constantly by accident and is not news.
    notifyFor(makeMessage($channel, $owner, "note to self @{$owner->username}"), $owner);

    expect(Notification::query()->forWorkspace($workspace, $owner)->count())->toBe(0);
});

it('writes no notification for an ordinary channel message, however loud the channel is set', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    ChannelMember::query()
        ->where('channel_id', $channel->id)
        ->where('user_id', $member->id)
        ->update(['notification_level' => NotificationLevel::All->value]);

    notifyFor(makeMessage($channel, $owner, 'morning all'), $owner);

    /*
     * The design decision this whole feature rests on: "all" drives the live
     * alert and the away email, but a row per member per message is the shape
     * Snack does not store.
     */
    expect(Notification::query()->forWorkspace($workspace, $member)->count())->toBe(0);
});

it('does not notify a mentioned person who has silenced the channel', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    ChannelMember::query()
        ->where('channel_id', $channel->id)
        ->where('user_id', $member->id)
        ->update(['notification_level' => NotificationLevel::Nothing->value]);

    notifyFor(makeMessage($channel, $owner, "@{$member->username} hello"), $owner);

    expect(Notification::query()->forWorkspace($workspace, $member)->count())->toBe(0);
});

it('still notifies a mentioned person who has merely muted the channel', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    // Muting says "stop telling me about the traffic here", not "stop telling
    // me when I am addressed by name".
    ChannelMember::query()
        ->where('channel_id', $channel->id)
        ->where('user_id', $member->id)
        ->update(['is_muted' => true]);

    notifyFor(makeMessage($channel, $owner, "@{$member->username} sorry to bother"), $owner);

    expect(Notification::query()->forWorkspace($workspace, $member)->count())->toBe(1);
});

it('notifies every other participant of a direct message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $other]);

    notifyFor(makeConversationMessage($conversation, $owner, 'are you free?'), $owner);

    expect(Notification::query()->forWorkspace($workspace, $other)->sole()->type)
        ->toBe(NotificationReason::DirectMessage->value)
        ->and(Notification::query()->forWorkspace($workspace, $owner)->count())->toBe(0);
});

it('notifies thread followers about a reply', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    $root = makeMessage($channel, $member, 'what do we think?');

    ThreadSubscription::query()->create([
        'user_id' => $member->id,
        'root_message_id' => $root->id,
    ]);

    $reply = Message::factory()
        ->inChannel($channel)
        ->from($owner)
        ->create(['parent_message_id' => $root->id]);

    notifyFor($reply, $owner);

    expect(Notification::query()->forWorkspace($workspace, $member)->sole()->type)
        ->toBe(NotificationReason::ThreadReply->value);
});

it('gives a person one notification for a reply that also names them', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    $root = makeMessage($channel, $member, 'thoughts?');

    ThreadSubscription::query()->create([
        'user_id' => $member->id,
        'root_message_id' => $root->id,
    ]);

    $reply = Message::factory()
        ->inChannel($channel)
        ->from($owner)
        ->withBody("@{$member->username} yes")
        ->create(['parent_message_id' => $root->id]);

    notifyFor($reply, $owner);

    $notifications = Notification::query()->forWorkspace($workspace, $member)->get();

    // One row, and it carries the stronger reason: being named outranks being
    // subscribed, and telling somebody twice for one event is noise.
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(NotificationReason::Mention->value);
});

it('broadcasts an arriving notification to the recipient', function () {
    [, $owner, $member, $channel] = notifiableChannel();

    notifyFor(makeMessage($channel, $owner, "@{$member->username} ping"), $owner);

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        fn (BroadcastNotificationCreated $event): bool => $event->notifiable->is($member),
    );
});

it('broadcasts the same shape the inbox renders', function () {
    [, $owner, $member, $channel] = notifiableChannel();

    notifyFor(makeMessage($channel, $owner, "@{$member->username} take a look"), $owner);

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event): bool {
        $payload = $event->broadcastWith();

        /*
         * `type` is the assertion that matters. BroadcastNotificationCreated
         * overwrites it *after* toBroadcast() has run, defaulting to the class
         * name — so without broadcastType() the client is handed a PHP namespace
         * where it switches on a reason, and renders nothing.
         */
        return $payload['type'] === NotificationReason::Mention->value
            && $payload['is_read'] === false
            && array_key_exists('preview', $payload)
            && array_key_exists('container', $payload)
            && array_key_exists('actor', $payload);
    });
});

it('shows the centre scoped to the workspace it is viewed in', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    $elsewhere = makeWorkspace();
    joinWorkspace($elsewhere, $member);
    foreignNotification($elsewhere, $member);

    notifyFor(makeMessage($channel, $owner, "@{$member->username} here"), $owner);

    $this->actingAs($member)
        ->get(route('notifications.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications/index')
            ->has('notifications', 1)
            ->where('notifications.0.type', NotificationReason::Mention->value));
});

it('hides a notification whose message has since been deleted', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    $message = makeMessage($channel, $owner, "@{$member->username} never mind");
    notifyFor($message, $owner);

    // The payload snapshots the body, so without the inbox's check the withdrawn
    // text would keep showing long after it left the channel.
    $message->delete();

    $this->actingAs($member)
        ->get(route('notifications.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications', 0));
});

it('counts unread notifications in the sidebar badge', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    notifyFor(makeMessage($channel, $owner, "@{$member->username} hi"), $owner);

    $this->actingAs($member)
        ->get(route('notifications.index', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page->where('snack.current.unread.notifications', 1));
});

it('marks a single notification read', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    notifyFor(makeMessage($channel, $owner, "@{$member->username} hi"), $owner);

    $notification = Notification::query()->forWorkspace($workspace, $member)->sole();

    $this->actingAs($member)
        ->post(route('notifications.read.store', [
            'workspace' => $workspace->slug,
            'notification' => $notification->id,
        ]))
        ->assertOk();

    expect($notification->fresh()->isRead())->toBeTrue();
});

it("leaves somebody else's notification alone", function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();
    $stranger = joinWorkspace($workspace);

    notifyFor(makeMessage($channel, $owner, "@{$member->username} hi"), $owner);

    $notification = Notification::query()->forWorkspace($workspace, $member)->sole();

    /*
     * The id could be pasted from anywhere; scoping every statement by
     * notifiable is what makes doing so update nothing. The response is a
     * success either way — a 404 here would confirm the id exists.
     */
    $this->actingAs($stranger)
        ->post(route('notifications.read.store', [
            'workspace' => $workspace->slug,
            'notification' => $notification->id,
        ]))
        ->assertOk();

    expect($notification->fresh()->isRead())->toBeFalse();
});

it('marks everything in the workspace read without touching another workspace', function () {
    [$workspace, $owner, $member, $channel] = notifiableChannel();

    $elsewhere = makeWorkspace();
    joinWorkspace($elsewhere, $member);
    $foreign = foreignNotification($elsewhere, $member);

    notifyFor(makeMessage($channel, $owner, "@{$member->username} hi"), $owner);

    $this->actingAs($member)
        ->from(route('notifications.index', ['workspace' => $workspace->slug]))
        ->patch(route('notifications.read.update', ['workspace' => $workspace->slug]))
        ->assertRedirect();

    expect(Notification::query()->forWorkspace($workspace, $member)->whereNull('read_at')->count())->toBe(0)
        ->and($foreign->fresh()->isRead())->toBeFalse();
});

it('keeps a non-member out of the centre entirely', function () {
    [$workspace] = makeWorkspaceWithOwner();

    // ResolveWorkspace answers 404 rather than 403, so the workspace's existence
    // is not confirmed to somebody who is not in it.
    $this->actingAs(User::factory()->create())
        ->get(route('notifications.index', ['workspace' => $workspace->slug]))
        ->assertNotFound();
});
