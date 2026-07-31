<?php

use App\Enums\ChannelType;
use App\Events\PinChanged;
use App\Models\Channel;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\SavedMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Pins and saves.
 *
 * Two features with the same shape and deliberately different authorization: a
 * pin is shared state that changes what everyone in the room sees, so it takes
 * posting rights; a save is a private bookmark, so it takes only read access.
 * Most of what is worth asserting is that the two do not drift into each other.
 */
function pin(Workspace $workspace, Message $message): TestResponse
{
    return test()->postJson(route('pins.store', [
        'workspace' => $workspace->slug,
        'message' => $message->id,
    ]));
}

function unpin(Workspace $workspace, Message $message): TestResponse
{
    return test()->deleteJson(route('pins.destroy', [
        'workspace' => $workspace->slug,
        'message' => $message->id,
    ]));
}

function save(Workspace $workspace, Message $message): TestResponse
{
    return test()->postJson(route('saves.store', [
        'workspace' => $workspace->slug,
        'message' => $message->id,
    ]));
}

function unsave(Workspace $workspace, Message $message): TestResponse
{
    return test()->deleteJson(route('saves.destroy', [
        'workspace' => $workspace->slug,
        'message' => $message->id,
    ]));
}

/**
 * @return array{0: Workspace, 1: User, 2: Channel, 3: Message}
 */
function makePinFixture(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $member);

    return [$workspace, $member, $channel, makeMessage($channel, $owner, 'The deploy runbook.')];
}

// -- Pinning -----------------------------------------------------------------

it('pins a message and returns the container pins', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);

    pin($workspace, $message)
        ->assertOk()
        ->assertJsonCount(1, 'pinned')
        ->assertJsonPath('pinned.0.id', $message->id);

    expect(PinnedMessage::count())->toBe(1);
});

it('denormalizes the container onto the pin', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();

    $row = PinnedMessage::query()->firstOrFail();

    // Taken from the message, never from the request: a pin must not be able to
    // name a container other than its message's own.
    expect($row->channel_id)->toBe($channel->id)
        ->and($row->conversation_id)->toBeNull()
        ->and($row->pinned_by)->toBe($member->id);
});

it('unpins a message', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();

    unpin($workspace, $message)->assertOk()->assertJsonCount(0, 'pinned');

    expect(PinnedMessage::count())->toBe(0);
});

it('lets anyone who could pin also unpin', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();

    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    /*
     * A pin belongs to the conversation, not to whoever clicked. Requiring the
     * original pinner would leave stale pins nobody present could clear.
     */
    $this->actingAs($other);
    unpin($workspace, $message)->assertOk();

    expect(PinnedMessage::count())->toBe(0);
});

it('treats a repeated pin as already done', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();
    pin($workspace, $message)->assertOk()->assertJsonCount(1, 'pinned');

    expect(PinnedMessage::count())->toBe(1);
    Event::assertDispatchedTimes(PinChanged::class, 1);
});

it('refuses a pin from someone who cannot post', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    $message = makeMessage($channel, $workspace->owner, 'Members only.');

    $this->actingAs($outsider);

    // Pinning changes what everyone in the room sees, so it takes the same
    // standing as saying something there.
    pin($workspace, $message)->assertForbidden();

    expect(PinnedMessage::count())->toBe(0);
});

it('hides a pin target in a private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $private = makeChannel($workspace, ChannelType::Private, ['name' => 'leadership']);
    $message = makeMessage($private, $workspace->owner, 'Private planning.');

    $this->actingAs($outsider);

    pin($workspace, $message)->assertNotFound();
});

it('tells the container when a pin changes', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();

    Event::assertDispatched(PinChanged::class, function (PinChanged $event) use ($channel, $message): bool {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === 'presence-channel.'.$channel->id
            && $payload['pinned'] === true
            && $payload['message']['id'] === $message->id;
    });
});

it('reports pin state on the message history', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    pin($workspace, $message)->assertOk();

    $this->getJson(route('channels.messages.index', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]))->assertOk()->assertJsonPath('messages.0.is_pinned', true);
});

// -- Saving ------------------------------------------------------------------

it('saves a message privately', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);

    save($workspace, $message)->assertOk()->assertJsonPath('is_saved', true);

    expect(SavedMessage::query()->where('user_id', $member->id)->count())->toBe(1);
});

it('unsaves a message', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();

    unsave($workspace, $message)->assertOk()->assertJsonPath('is_saved', false);

    expect(SavedMessage::count())->toBe(0);
});

it('treats a repeated save as already done', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();
    save($workspace, $message)->assertOk();

    expect(SavedMessage::count())->toBe(1);
});

it('never broadcasts a save', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();

    // Telling the channel what you are keeping track of is the opposite of what
    // a private bookmark is for.
    Event::assertNotDispatched(PinChanged::class);
});

it('allows a save with only read access', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $previewer = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    $message = makeMessage($channel, $workspace->owner, 'Public, unjoined.');

    $this->actingAs($previewer);

    // Anything you may read, you may keep a pointer to — unlike a pin.
    save($workspace, $message)->assertOk();

    expect(SavedMessage::count())->toBe(1);
});

it('hides a save target in a private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $private = makeChannel($workspace, ChannelType::Private, ['name' => 'leadership']);
    $message = makeMessage($private, $workspace->owner, 'Private planning.');

    $this->actingAs($outsider);

    save($workspace, $message)->assertNotFound();
});

it('reports save state only to the person who saved it', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();

    $args = ['workspace' => $workspace->slug, 'channel' => $channel->slug];

    $this->getJson(route('channels.messages.index', $args))
        ->assertOk()
        ->assertJsonPath('messages.0.is_saved', true);

    // The same message, to somebody else, is not saved.
    $this->actingAs($message->sender);

    $this->getJson(route('channels.messages.index', $args))
        ->assertOk()
        ->assertJsonPath('messages.0.is_saved', false);
});

// -- The Saved view ----------------------------------------------------------

it('lists saved messages newest first', function () {
    [$workspace, $member, $channel] = makePinFixture();

    $first = makeMessage($channel, $member, 'Older note.');
    $second = makeMessage($channel, $member, 'Newer note.');

    $this->actingAs($member);
    save($workspace, $first)->assertOk();
    save($workspace, $second)->assertOk();

    $this->get(route('saved.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('saved/index')
            ->has('saved', 2)
            ->where('saved.0.message.id', $second->id)
            ->where('saved.0.container.slug', $channel->slug));
});

it('shows only the viewer\'s own saves', function () {
    [$workspace, $member, $channel, $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();

    $bystander = joinWorkspace($workspace);
    addToChannel($channel, $bystander);

    $this->actingAs($bystander);

    $this->get(route('saved.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('saved', 0));
});

it('does not list a save from another workspace', function () {
    [$workspace, $member, , $message] = makePinFixture();

    $this->actingAs($member);
    save($workspace, $message)->assertOk();

    $elsewhere = makeWorkspace();
    joinWorkspace($elsewhere, $member);

    $this->get(route('saved.index', ['workspace' => $elsewhere->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('saved', 0));
});

it('drops a save whose message was deleted', function () {
    [$workspace, $member, $channel] = makePinFixture();

    $own = makeMessage($channel, $member, 'Mine to delete.');

    $this->actingAs($member);
    save($workspace, $own)->assertOk();

    $this->deleteJson(route('messages.destroy', [
        'workspace' => $workspace->slug,
        'message' => $own->id,
    ]))->assertOk();

    // A bookmark on a deleted message drops out rather than rendering as a gap.
    $this->get(route('saved.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('saved', 0));
});
