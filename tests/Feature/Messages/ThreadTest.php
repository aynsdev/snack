<?php

use App\Enums\ChannelType;
use App\Events\MessageSent;
use App\Events\ThreadActivity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ThreadSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UnreadCounter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Threads.
 *
 * Replies travel the same send path as any other message — the only new input is
 * `parent_message_id` — so these tests concentrate on what that one field can be
 * made to do, and on the counters and pointers a reply moves on its way through.
 */
function replyTo(Workspace $workspace, Channel $channel, Message $parent, string $body): TestResponse
{
    return test()->postJson(
        route('channels.messages.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]),
        ['body' => $body, 'parent_message_id' => $parent->id],
    );
}

/**
 * A workspace, a member, a channel they belong to, and a message to reply to.
 *
 * @return array{0: Workspace, 1: User, 2: Channel, 3: Message}
 */
function makeThreadFixture(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);
    addToChannel($channel, $owner);

    return [$workspace, $member, $channel, makeMessage($channel, $owner, 'What should we call it?')];
}

// -- Posting replies ---------------------------------------------------------

it('posts a reply into the parent thread', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);

    replyTo($workspace, $channel, $root, 'Snack.')
        ->assertCreated()
        ->assertJsonPath('message.parent_message_id', $root->id)
        ->assertJsonPath('message.body', 'Snack.');

    expect($root->fresh()->reply_count)->toBe(1);
});

it('keeps replies out of the channel timeline', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    // The timeline is root-level only; a reply that leaked into it would appear
    // twice to anyone with the thread open.
    $this->getJson(route('channels.messages.index', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]))->assertOk()->assertJsonCount(1, 'messages');
});

it('flattens a reply to a reply into the same thread', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);

    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();
    $reply = Message::query()->where('parent_message_id', $root->id)->firstOrFail();

    // Answering the answer joins the thread rather than nesting below it.
    replyTo($workspace, $channel, $reply, 'Seconded.')
        ->assertCreated()
        ->assertJsonPath('message.parent_message_id', $root->id);

    expect($root->fresh()->reply_count)->toBe(2);
});

it('leaves the channel tally and ordering alone for a reply', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $before = $channel->fresh();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    // A reply is thread activity: the sidebar orders by messages the timeline
    // can actually show, and the badge counts the same set.
    expect($channel->fresh()->message_count)->toBe($before->message_count)
        ->and($channel->fresh()->last_message_at?->toIso8601String())
        ->toBe($before->last_message_at?->toIso8601String());
});

// -- The parent id is untrusted input ---------------------------------------

it('refuses a parent from another channel', function () {
    [$workspace, $member, $channel] = makeThreadFixture();

    $elsewhere = makeChannel($workspace, ChannelType::Private, ['name' => 'leadership']);
    $foreign = makeMessage($elsewhere, $workspace->owner, 'Private planning.');

    $this->actingAs($member);

    /*
     * The parent is resolved through the channel's own messages relation, so a
     * message from a channel the sender cannot see does not resolve at all —
     * and answers 404 rather than 403, which would confirm it exists.
     */
    replyTo($workspace, $channel, $foreign, 'Butting in.')->assertNotFound();

    expect(Message::query()->whereNotNull('parent_message_id')->count())->toBe(0);
});

it('refuses a parent from another workspace', function () {
    [$workspace, $member, $channel] = makeThreadFixture();

    $other = makeWorkspace();
    $otherChannel = makeChannel($other);
    $foreign = makeMessage($otherChannel, $other->owner, 'Different company entirely.');

    $this->actingAs($member);

    replyTo($workspace, $channel, $foreign, 'Crossing tenants.')->assertNotFound();

    expect(Message::query()->whereNotNull('parent_message_id')->count())->toBe(0);
});

it('refuses a parent that does not exist', function () {
    [$workspace, $member, $channel] = makeThreadFixture();

    $this->actingAs($member);

    $this->postJson(
        route('channels.messages.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]),
        ['body' => 'Into the void.', 'parent_message_id' => 99_999],
    )->assertNotFound();
});

// -- Reading a thread --------------------------------------------------------

it('returns the root and its replies in posting order', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'First.')->assertCreated();
    replyTo($workspace, $channel, $root, 'Second.')->assertCreated();

    $this->getJson(route('threads.show', ['workspace' => $workspace->slug, 'message' => $root->id]))
        ->assertOk()
        ->assertJsonPath('root.id', $root->id)
        ->assertJsonCount(2, 'replies')
        // Forward from the root: a thread is read start to finish.
        ->assertJsonPath('replies.0.body', 'First.')
        ->assertJsonPath('replies.1.body', 'Second.');
});

it('opens the whole thread when addressed by one of its replies', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'First.')->assertCreated();
    $reply = Message::query()->where('parent_message_id', $root->id)->firstOrFail();

    // A link out of a mention or search names the reply, not the root, and must
    // still land on the thread rather than an empty panel.
    $this->getJson(route('threads.show', ['workspace' => $workspace->slug, 'message' => $reply->id]))
        ->assertOk()
        ->assertJsonPath('root.id', $root->id)
        ->assertJsonCount(1, 'replies');
});

it('hides a thread in a channel the viewer cannot see', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $private = makeChannel($workspace, ChannelType::Private, ['name' => 'leadership']);
    $root = makeMessage($private, $workspace->owner, 'Private planning.');

    $this->actingAs($outsider);

    $this->getJson(route('threads.show', ['workspace' => $workspace->slug, 'message' => $root->id]))
        ->assertNotFound();
});

// -- Subscriptions -----------------------------------------------------------

it('subscribes the replier and the root author when a reply lands', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $subscriptions = ThreadSubscription::query()->where('root_message_id', $root->id)->get();

    expect($subscriptions)->toHaveCount(2)
        ->and($subscriptions->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$member->id, $root->sender_id])->sort()->values()->all());
});

it('does not badge the replier for their own reply', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $reply = Message::query()->where('parent_message_id', $root->id)->firstOrFail();

    $mine = ThreadSubscription::query()
        ->where('root_message_id', $root->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect($mine->last_read_reply_id)->toBe($reply->id)
        ->and($mine->hasUnread($root->fresh()->last_reply_id))->toBeFalse();
});

it('leaves the root author with the reply unread', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $theirs = ThreadSubscription::query()
        ->where('root_message_id', $root->id)
        ->where('user_id', $root->sender_id)
        ->firstOrFail();

    // Being answered is precisely the thing worth telling someone about.
    expect($theirs->last_read_reply_id)->toBeNull()
        ->and($theirs->hasUnread($root->fresh()->last_reply_id))->toBeTrue();
});

it('creates no subscription rows for a message nobody replies to', function () {
    [, , , $root] = makeThreadFixture();

    expect(ThreadSubscription::query()->where('root_message_id', $root->id)->count())->toBe(0);
});

it('follows and unfollows a thread explicitly', function () {
    [$workspace, $member, , $root] = makeThreadFixture();

    $this->actingAs($member);

    $this->postJson(route('threads.subscription.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]))->assertOk()->assertJsonPath('is_subscribed', true);

    $this->deleteJson(route('threads.subscription.destroy', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]))->assertOk()->assertJsonPath('is_subscribed', false);

    expect(ThreadSubscription::query()->where('root_message_id', $root->id)->count())->toBe(0);
});

it('does not rewind the read pointer when re-following', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $reply = Message::query()->where('parent_message_id', $root->id)->firstOrFail();

    // Re-following an already-read thread must not resurrect its badge.
    $this->postJson(route('threads.subscription.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]))->assertOk();

    expect(ThreadSubscription::query()
        ->where('root_message_id', $root->id)
        ->where('user_id', $member->id)
        ->value('last_read_reply_id'))->toBe($reply->id);
});

// -- Read pointer ------------------------------------------------------------

it('advances the thread read pointer', function () {
    [$workspace, $owner, $channel, $root] = makeThreadFixture();
    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $this->actingAs($other);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $reply = Message::query()->where('parent_message_id', $root->id)->firstOrFail();

    $this->actingAs($root->sender);

    $this->postJson(route('threads.read.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]), ['last_read_reply_id' => $reply->id])
        ->assertOk()
        ->assertJsonPath('last_read_reply_id', $reply->id);

    expect(ThreadSubscription::query()
        ->where('root_message_id', $root->id)
        ->where('user_id', $root->sender_id)
        ->value('last_read_reply_id'))->toBe($reply->id);
});

it('refuses a read pointer from a different thread', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $elsewhere = makeMessage($channel, $member, 'A separate conversation.');
    $this->actingAs($member);
    replyTo($workspace, $channel, $elsewhere, 'Unrelated.')->assertCreated();

    $foreignReply = Message::query()->where('parent_message_id', $elsewhere->id)->firstOrFail();

    // A pointer parked on another thread's id would corrupt this
    // subscription's arithmetic for the rest of its life.
    $this->postJson(route('threads.read.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]), ['last_read_reply_id' => $foreignReply->id])
        ->assertJsonValidationErrorFor('last_read_reply_id');
});

it('never moves the read pointer backwards', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'First.')->assertCreated();
    replyTo($workspace, $channel, $root, 'Second.')->assertCreated();

    $replies = Message::query()->where('parent_message_id', $root->id)->orderBy('id')->get();

    // A tab left open on older history syncs a stale position eventually;
    // honouring it would resurrect a badge the reader already cleared.
    $this->postJson(route('threads.read.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]), ['last_read_reply_id' => $replies->first()->id])->assertOk();

    expect(ThreadSubscription::query()
        ->where('root_message_id', $root->id)
        ->where('user_id', $member->id)
        ->value('last_read_reply_id'))->toBe($replies->last()->id);
});

// -- Deleting a reply --------------------------------------------------------

it('takes a deleted reply back out of the thread counters', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'First.')->assertCreated();
    replyTo($workspace, $channel, $root, 'Second.')->assertCreated();

    $newest = Message::query()->where('parent_message_id', $root->id)->orderByDesc('id')->firstOrFail();

    $this->deleteJson(route('messages.destroy', [
        'workspace' => $workspace->slug,
        'message' => $newest->id,
    ]))->assertOk();

    $fresh = $root->fresh();

    /*
     * The pointer is recomputed, not decremented: left on the deleted row it
     * would badge the thread as unread forever, since the reply a follower
     * would have to read no longer exists.
     */
    expect($fresh->reply_count)->toBe(1)
        ->and($fresh->last_reply_id)->toBeLessThan($newest->id);
});

// -- Broadcasting ------------------------------------------------------------

it('broadcasts a reply to the thread rather than the channel', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($root): bool {
        $channels = $event->broadcastOn();

        // A reply on the container channel would hand its body to every member
        // of the channel, including everyone who never opens the thread.
        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-thread.'.$root->id;
    });
});

it('tells the container that a thread moved, without the reply body', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    Event::assertDispatched(ThreadActivity::class, function (ThreadActivity $event) use ($channel, $root): bool {
        $payload = $event->broadcastWith();
        $channels = $event->broadcastOn();

        return $channels[0] instanceof PresenceChannel
            && $channels[0]->name === 'presence-channel.'.$channel->id
            && $payload['root_id'] === $root->id
            && $payload['reply_count'] === 1
            && ! array_key_exists('body', $payload);
    });
});

// -- The badge ---------------------------------------------------------------

it('counts threads rather than replies in the badge', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();
    $owner = $root->sender;

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'First.')->assertCreated();
    replyTo($workspace, $channel, $root, 'Second.')->assertCreated();
    replyTo($workspace, $channel, $root, 'Third.')->assertCreated();

    // Three unread replies in one thread is one thing waiting on you, not three.
    expect(app(UnreadCounter::class)->threadCount($workspace, $owner))->toBe(1);
});

it('clears the badge once the thread is read', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();
    $owner = $root->sender;

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $counter = app(UnreadCounter::class);
    expect($counter->threadCount($workspace, $owner))->toBe(1);

    $this->actingAs($owner);
    $this->postJson(route('threads.read.store', [
        'workspace' => $workspace->slug,
        'message' => $root->id,
    ]))->assertOk();

    expect($counter->threadCount($workspace, $owner))->toBe(0);
});

it('does not badge threads from other workspaces', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();
    $owner = $root->sender;

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $elsewhere = makeWorkspace();

    expect(app(UnreadCounter::class)->threadCount($elsewhere, $owner))->toBe(0);
});

// -- The Threads view --------------------------------------------------------

it('lists followed threads newest activity first', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $second = makeMessage($channel, $root->sender, 'A later question.');

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Answering the first.')->assertCreated();
    replyTo($workspace, $channel, $second, 'Answering the second.')->assertCreated();

    $this->get(route('threads.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('threads/index')
            ->has('threads', 2)
            ->where('threads.0.root.id', $second->id)
            ->where('threads.0.container.name', $channel->name));
});

it('lists only the viewer\'s own threads', function () {
    [$workspace, $member, $channel, $root] = makeThreadFixture();

    $this->actingAs($member);
    replyTo($workspace, $channel, $root, 'Snack.')->assertCreated();

    $bystander = joinWorkspace($workspace);
    addToChannel($channel, $bystander);

    $this->actingAs($bystander);

    $this->get(route('threads.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('threads', 0));
});
