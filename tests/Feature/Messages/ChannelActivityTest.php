<?php

use App\Actions\Messages\SendMessage;
use App\Events\ChannelActivity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * The signal that badges a channel for people who are not looking at it.
 *
 * `MessageSent` goes to the channel's own presence channel, which only reaches
 * people who already have it open — so without this event a message in #design
 * while you were reading #general produced nothing at all until you happened to
 * reload. Conversations had `ConversationActivity` from the start; channels had
 * no counterpart, and that asymmetry is what these tests pin down.
 *
 * Everything here goes through {@see SendMessage} rather than the message
 * factory: the factory writes a row, and the whole subject of this file is what
 * the *action* publishes around it.
 */
function sendToChannel(Channel $channel, User $sender, string $body = 'Hello', ?Message $parent = null): Message
{
    return app(SendMessage::class)->handle($channel, $sender, $body, $parent);
}

it('tells the other members a message arrived', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $ada);
    addToChannel($channel, $bo);

    sendToChannel($channel, $ada, 'Anyone around?');

    Event::assertDispatched(
        ChannelActivity::class,
        function (ChannelActivity $event) use ($channel, $ada, $bo, $owner): bool {
            $actual = $event->recipientIds;
            sort($actual);

            $expected = [$owner->id, $bo->id];
            sort($expected);

            return $event->channelId === $channel->id
                // The sender is excluded: their own timeline already received
                // the message and their sidebar has nothing to badge.
                && ! in_array($ada->id, $actual, true)
                && $actual === $expected;
        },
    );
});

it('reaches each member on their own private channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $ada);

    sendToChannel($channel, $ada);

    Event::assertDispatched(ChannelActivity::class, function (ChannelActivity $event) use ($owner): bool {
        $names = array_map(
            fn ($broadcastChannel): string => (string) $broadcastChannel->name,
            $event->broadcastOn(),
        );

        /*
         * A member's own `user.{id}` channel, not the workspace's. One
         * broadcast to the workspace would be cheaper, but every member of the
         * workspace would receive it — including for private channels they
         * cannot see, leaking the timing of activity in them.
         */
        return $names === ['private-user.'.$owner->id];
    });
});

it('carries the channel id and no message content', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $ada);

    sendToChannel($channel, $ada, 'Something confidential');

    Event::assertDispatched(ChannelActivity::class, function (ChannelActivity $event) use ($channel): bool {
        return $event->broadcastWith() === ['channel_id' => $channel->id]
            && ! str_contains((string) json_encode($event->broadcastWith()), 'confidential');
    });
});

it('says nothing when the sender is the only member', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    sendToChannel($channel, $owner, 'Talking to myself');

    // Nobody to tell, so nothing is published.
    Event::assertNotDispatched(ChannelActivity::class);
});

it('does not badge the container for a thread reply', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $ada);

    $root = sendToChannel($channel, $owner, 'Parent');

    sendToChannel($channel, $ada, 'A reply', $root);

    /*
     * A reply is thread activity. The container's timeline excludes replies, so
     * badging the channel would light up a row whose message list shows nothing
     * new — the same reasoning that keeps `last_message_at` untouched.
     *
     * Counted rather than re-faked: a second `Event::fake()` inside a test
     * replaces the global one from tests/Pest.php, which un-fakes every other
     * broadcast and sends the reply's `ThreadActivity` at a real Reverb server.
     * The parent's own signal is the one dispatch expected here.
     */
    Event::assertDispatchedTimes(ChannelActivity::class, 1);
});
