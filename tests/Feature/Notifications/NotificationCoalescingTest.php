<?php

use App\Actions\Messages\SyncMentions;
use App\Enums\NotificationReason;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverMessageNotifications;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * A chatty direct message should be worth one inbox row, not one per line.
 *
 * The rule lives in App\Services\NotificationCoalescer: a direct message folds
 * into the row already sitting unread for that conversation, provided it is
 * recent enough. Everything here is about the edges of that sentence — read
 * state, the window, the container, and the reason.
 */

/**
 * A conversation between two people in one workspace.
 *
 * @return array{0: Workspace, 1: User, 2: User, 3: Conversation}
 */
function coalescingConversation(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace, role: WorkspaceRole::Member);

    return [$workspace, $owner, $other, makeConversation($workspace, [$owner, $other])];
}

/**
 * A channel both people are in, for the mention assertions.
 *
 * Declared here rather than borrowed from NotificationCentreTest: a helper in
 * another test file only exists when that file happens to have been loaded, so
 * running this one under --filter would fatal.
 *
 * @return array{0: Workspace, 1: User, 2: User, 3: Channel}
 */
function coalescingChannel(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $member);

    return [$workspace, $owner, $member, $channel];
}

/** Run the fan-out for a new message in the conversation. */
function sendDirect(Conversation $conversation, User $sender, string $body): void
{
    DeliverMessageNotifications::dispatch(
        makeConversationMessage($conversation, $sender, $body)->id,
    );
}

/**
 * A channel message, mentions materialized first — the fan-out reads those rows
 * to decide who was named.
 */
function sendMention(Channel $channel, User $sender, string $body): void
{
    $message = makeMessage($channel, $sender, $body);

    app(SyncMentions::class)->handle($message, $sender);

    DeliverMessageNotifications::dispatch($message->id);
}

it('folds a second direct message into the row already unread', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    sendDirect($conversation, $owner, 'are you around?');
    sendDirect($conversation, $owner, 'never mind, found it');

    $notification = Notification::query()->forWorkspace($workspace, $other)->sole();

    expect($notification->type)->toBe(NotificationReason::DirectMessage->value)
        ->and(data_get($notification->data, 'count'))->toBe(2)
        // The row describes the latest message, not the one that opened it —
        // the whole point is that clicking it lands you where the conversation
        // actually is.
        ->and(data_get($notification->data, 'preview'))->toContain('never mind');
});

it('keeps folding however many arrive', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    foreach (range(1, 5) as $index) {
        sendDirect($conversation, $owner, "line {$index}");
    }

    $notification = Notification::query()->forWorkspace($workspace, $other)->sole();

    expect(data_get($notification->data, 'count'))->toBe(5)
        ->and(data_get($notification->data, 'preview'))->toContain('line 5');
});

it('starts a new row once the reader has seen the last one', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    sendDirect($conversation, $owner, 'first');

    Notification::query()->forWorkspace($workspace, $other)->sole()->markAsRead();

    sendDirect($conversation, $owner, 'second');

    $notifications = Notification::query()->forWorkspace($workspace, $other)->get();

    // Two rows, because the reader dealt with the first. Folding into a row
    // somebody has already read would silently reopen it.
    expect($notifications)->toHaveCount(2)
        ->and($notifications->whereNull('read_at')->count())->toBe(1);
});

it('starts a new row once the window has passed', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    sendDirect($conversation, $owner, 'this morning');

    /*
     * Backdated past the coalescing window. Without that bound an unread row
     * nobody ever opens would absorb every direct message forever, and the
     * away-email would stop going out after the first one — which is worse than
     * the pile-up this feature exists to prevent.
     */
    Notification::query()
        ->forWorkspace($workspace, $other)
        ->sole()
        ->forceFill([
            'created_at' => now()->subMinutes(
                (int) config('snack.notifications.coalesce_minutes') + 1,
            ),
        ])
        ->save();

    sendDirect($conversation, $owner, 'this afternoon');

    expect(Notification::query()->forWorkspace($workspace, $other)->count())->toBe(2);
});

it('does not fold a different conversation into the same row', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    $third = joinWorkspace($workspace, role: WorkspaceRole::Member);

    sendDirect($conversation, $owner, 'from the owner');
    sendDirect(makeConversation($workspace, [$third, $other]), $third, 'from someone else');

    // Two conversations are two things to answer, however close together they
    // arrive.
    expect(Notification::query()->forWorkspace($workspace, $other)->count())->toBe(2);
});

it('does not fold mentions', function () {
    [$workspace, $owner, $member, $channel] = coalescingChannel();

    sendMention($channel, $owner, "@{$member->username} one");
    sendMention($channel, $owner, "@{$member->username} two");

    // Being named twice is two things to go and look at, possibly in two
    // different places. Collapsing them would hide one behind a count.
    expect(Notification::query()->forWorkspace($workspace, $member)->count())->toBe(2);
});

it('broadcasts the fold under the existing row id', function () {
    [$workspace, $owner, $other, $conversation] = coalescingConversation();

    sendDirect($conversation, $owner, 'first');

    $id = Notification::query()->forWorkspace($workspace, $other)->sole()->id;

    sendDirect($conversation, $owner, 'second');

    /*
     * The same event a fresh notification arrives on, carrying the id the client
     * is already rendering — that is what makes the open tab replace the row
     * rather than grow a duplicate. (BroadcastNotificationCreated is faked for
     * the whole suite in tests/Pest.php.)
     */
    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        function (BroadcastNotificationCreated $event) use ($id, $other): bool {
            $payload = $event->broadcastWith();

            return $event->notifiable->is($other)
                && $payload['id'] === $id
                && $payload['count'] === 2
                && $payload['type'] === NotificationReason::DirectMessage->value;
        },
    );
});

it('emails once for a burst rather than once per message', function () {
    [, $owner, $other, $conversation] = coalescingConversation();

    // Away, so mail is in play at all — see App\Services\NotificationDelivery.
    $other->forceFill(['last_seen_at' => now()->subHours(2)])->save();

    sendDirect($conversation, $owner, 'one');
    sendDirect($conversation, $owner, 'two');
    sendDirect($conversation, $owner, 'three');

    /*
     * Only the first message constructs a notification at all; the rest rewrite
     * the row it wrote, and a rewrite has no channels. Three emails describing
     * one interruption is how an application teaches people to filter its mail.
     *
     * Read off the array transport (phpunit.xml) rather than through
     * Notification::fake(), which would stop the database channel writing the
     * very rows the folding is keyed on.
     */
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('gives every row a count, folded or not', function () {
    [$workspace, $owner, $member, $channel] = coalescingChannel();

    sendMention($channel, $owner, "@{$member->username} hello");

    // Stated on every row rather than only on the folded ones, so the client
    // never has to treat a missing count as a third case.
    expect(data_get(
        Notification::query()->forWorkspace($workspace, $member)->sole()->data,
        'count',
    ))->toBe(1);
});
