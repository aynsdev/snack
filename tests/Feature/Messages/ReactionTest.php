<?php

use App\Enums\ChannelType;
use App\Events\ReactionChanged;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Reactions.
 *
 * Two idempotent endpoints rather than a toggle, so most of what is worth
 * asserting here is that repeating or reordering a request converges instead of
 * flapping — plus the usual: the emoji is untrusted input, and access is
 * inherited from the message's container.
 */
function react(Workspace $workspace, Message $message, string $emoji): TestResponse
{
    return test()->postJson(
        route('reactions.store', ['workspace' => $workspace->slug, 'message' => $message->id]),
        ['emoji' => $emoji],
    );
}

function unreact(Workspace $workspace, Message $message, string $emoji): TestResponse
{
    return test()->deleteJson(
        route('reactions.destroy', ['workspace' => $workspace->slug, 'message' => $message->id]),
        ['emoji' => $emoji],
    );
}

/**
 * A workspace, a member of a channel, and a message to react to.
 *
 * @return array{0: Workspace, 1: User, 2: Channel, 3: Message}
 */
function makeReactionFixture(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);
    addToChannel($channel, $owner);

    return [$workspace, $member, $channel, makeMessage($channel, $owner, 'Shipping today.')];
}

// -- Adding and removing -----------------------------------------------------

it('adds a reaction and returns the grouped summary', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);

    react($workspace, $message, 'tada')
        ->assertOk()
        ->assertJsonPath('message_id', $message->id)
        ->assertJsonPath('reactions.0.emoji', 'tada')
        ->assertJsonPath('reactions.0.count', 1)
        ->assertJsonPath('reactions.0.user_ids.0', $member->id)
        ->assertJsonPath('reactions.0.names.0', $member->name);
});

it('removes a reaction', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);
    react($workspace, $message, 'tada')->assertOk();

    unreact($workspace, $message, 'tada')
        ->assertOk()
        ->assertJsonCount(0, 'reactions');

    expect(MessageReaction::count())->toBe(0);
});

it('groups a reaction added by several people', function () {
    [$workspace, $member, $channel, $message] = makeReactionFixture();
    $other = joinWorkspace($workspace);
    addToChannel($channel, $other);

    $this->actingAs($member);
    react($workspace, $message, 'tada')->assertOk();

    $this->actingAs($other);
    react($workspace, $message, 'tada')
        ->assertOk()
        ->assertJsonCount(1, 'reactions')
        ->assertJsonPath('reactions.0.count', 2);
});

// -- Idempotence -------------------------------------------------------------

it('treats a repeated add as already done', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);

    react($workspace, $message, 'tada')->assertOk();

    /*
     * The double-click case. The unique index is the authority on "one of each
     * per person", so a duplicate is the state the caller asked for — not a 500.
     */
    react($workspace, $message, 'tada')
        ->assertOk()
        ->assertJsonPath('reactions.0.count', 1);

    expect(MessageReaction::count())->toBe(1);
});

it('treats a repeated remove as already done', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);

    unreact($workspace, $message, 'tada')
        ->assertOk()
        ->assertJsonCount(0, 'reactions');
});

it('does not broadcast when nothing actually changed', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);
    react($workspace, $message, 'tada')->assertOk();

    Event::assertDispatchedTimes(ReactionChanged::class, 1);

    // A second identical add changes nothing, so nobody needs telling.
    react($workspace, $message, 'tada')->assertOk();

    Event::assertDispatchedTimes(ReactionChanged::class, 1);
});

// -- The emoji is untrusted input -------------------------------------------

it('refuses an emoji outside the configured set', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);

    /*
     * A free-text shortcode would store anything a crafted request sent, and
     * every unrecognized value would render as a blank pill nobody could
     * identify or remove.
     */
    react($workspace, $message, 'not_a_real_emoji')
        ->assertJsonValidationErrorFor('emoji');

    expect(MessageReaction::count())->toBe(0);
});

it('refuses an emoji that is script rather than a shortcode', function () {
    [$workspace, $member, , $message] = makeReactionFixture();

    $this->actingAs($member);

    react($workspace, $message, '<script>alert(1)</script>')
        ->assertJsonValidationErrorFor('emoji');

    expect(MessageReaction::count())->toBe(0);
});

// -- Authorization -----------------------------------------------------------

it('refuses a reaction in a channel the user has not joined', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    $message = makeMessage($channel, $workspace->owner, 'Members only.');

    $this->actingAs($outsider);

    // Reacting is a contribution, so it needs posting rights rather than the
    // read access a public channel preview grants.
    react($workspace, $message, 'tada')->assertForbidden();

    expect(MessageReaction::count())->toBe(0);
});

it('hides a message in a private channel from a non-member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $private = makeChannel($workspace, ChannelType::Private, ['name' => 'leadership']);
    $message = makeMessage($private, $workspace->owner, 'Private planning.');

    $this->actingAs($outsider);

    // 404 rather than 403: a 403 would confirm the message exists.
    react($workspace, $message, 'tada')->assertNotFound();
});

it('refuses a reaction on a system message', function () {
    [$workspace, $member, $channel] = makeReactionFixture();

    $system = Message::factory()
        ->inChannel($channel)
        ->system('Ada added Bo to the channel.')
        ->create();

    $this->actingAs($member);

    react($workspace, $system, 'tada')->assertForbidden();
});

it('refuses a message from another workspace', function () {
    [$workspace, $member] = makeReactionFixture();

    $other = makeWorkspace();
    $foreign = makeMessage(makeChannel($other), $other->owner, 'Different company.');

    $this->actingAs($member);

    // The route binding is workspace-scoped, so this never reaches a policy.
    react($workspace, $foreign, 'tada')->assertNotFound();
});

// -- Delivery ----------------------------------------------------------------

it('broadcasts the whole summary to the container', function () {
    [$workspace, $member, $channel, $message] = makeReactionFixture();

    $this->actingAs($member);
    react($workspace, $message, 'tada')->assertOk();

    Event::assertDispatched(ReactionChanged::class, function (ReactionChanged $event) use ($channel, $message): bool {
        $payload = $event->broadcastWith();
        $channels = $event->broadcastOn();

        /*
         * The full state, not a delta: a client that missed one broadcast would
         * otherwise carry the drift until it reloaded.
         */
        return $channels[0] instanceof PresenceChannel
            && $channels[0]->name === 'presence-channel.'.$channel->id
            && $payload['message_id'] === $message->id
            && $payload['reactions'][0]['emoji'] === 'tada'
            && $payload['reactions'][0]['count'] === 1;
    });
});

it('broadcasts a reaction on a reply to the thread, not the channel', function () {
    [$workspace, $member, $channel, $root] = makeReactionFixture();

    $reply = Message::factory()->inChannel($channel)->from($member)->replyTo($root)->create();

    $this->actingAs($member);
    react($workspace, $reply, 'tada')->assertOk();

    Event::assertDispatched(ReactionChanged::class, function (ReactionChanged $event) use ($root): bool {
        $channels = $event->broadcastOn();

        // Same audience that can see the message being reacted to.
        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-thread.'.$root->id;
    });
});

it('carries reactions on the message history', function () {
    [$workspace, $member, $channel, $message] = makeReactionFixture();

    $this->actingAs($member);
    react($workspace, $message, 'rocket')->assertOk();

    $this->getJson(route('channels.messages.index', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]))
        ->assertOk()
        ->assertJsonPath('messages.0.reactions.0.emoji', 'rocket')
        ->assertJsonPath('messages.0.reactions.0.count', 1);
});
