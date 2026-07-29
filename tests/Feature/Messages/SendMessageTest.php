<?php

use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Enums\WorkspaceRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Posting a message.
 *
 * Sending is guarded by the same permission as ChannelPolicy::post, so rather
 * than restating every rule proven in ChannelAuthorizationTest, this exercises
 * the ones a send could plausibly slip past.
 */
function sendTo(Workspace $workspace, Channel $channel, string $body): TestResponse
{
    return test()->postJson(
        route('channels.messages.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]),
        ['body' => $body],
    );
}

it('posts a message to a channel the user has joined', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Morning, all.')
        ->assertCreated()
        ->assertJsonPath('message.body', 'Morning, all.')
        ->assertJsonPath('message.sender.id', $member->id)
        ->assertJsonPath('message.can.update', true);

    expect($channel->messages()->count())->toBe(1);
});

it('derives the workspace from the channel rather than the request', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    // The only authority for tenancy is the channel the route resolved; a
    // workspace identifier from the client is never consulted.
    sendTo($workspace, $channel, 'Hello.')->assertCreated();

    expect(Message::first()->workspace_id)->toBe($workspace->id);
});

it('refuses to post in a channel the user has not joined', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Sneaking in.')->assertForbidden();

    expect(Message::count())->toBe(0);
});

it('hides a private channel from a non-member trying to post', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($member);

    // 404, not 403: a private channel does not confirm its own existence.
    sendTo($workspace, $channel, 'Anyone there?')->assertNotFound();
});

it('stops a member of another workspace from posting', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    $this->actingAs($outsider);

    sendTo($workspace, $channel, 'Wrong workspace.')->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('restricts posting in an announcement channel to admins', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace, ChannelType::Announcement);

    $member = joinWorkspace($workspace);
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    addToChannel($channel, $member);
    addToChannel($channel, $admin);

    $this->actingAs($member);
    sendTo($workspace, $channel, 'Can I?')->assertForbidden();

    $this->actingAs($admin);
    sendTo($workspace, $channel, 'Company update.')->assertCreated();

    expect(Message::first()->type)->toBe(MessageType::Announcement);
});

it('refuses to post in an archived channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, attributes: ['is_archived' => true]);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Still here?')->assertForbidden();
});

it('rejects an empty message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, '   ')->assertJsonValidationErrorFor('body');
});

it('rejects a message beyond the configured length', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    $tooLong = str_repeat('a', (int) config('snack.messages.max_length') + 1);

    sendTo($workspace, $channel, $tooLong)->assertJsonValidationErrorFor('body');
});

it('stores script-like text verbatim instead of escaping or stripping it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    $hostile = '<script>alert(1)</script>';

    sendTo($workspace, $channel, $hostile)
        ->assertCreated()
        ->assertJsonPath('message.body', $hostile);

    /*
     * The body survives byte-for-byte on purpose. Nothing ever renders it as
     * markup: the client tokenizes source text into React elements, where this
     * becomes a text node reading "<script>alert(1)</script>". Escaping on the
     * way in would corrupt the message of anyone legitimately discussing HTML,
     * and would double-escape once the client rendered it.
     */
    expect(Message::first()->body)->toBe($hostile);
});

it('strips characters that would make a message render differently from its contents', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    // A right-to-left override reverses the visible text without changing the
    // characters, which is how a link or a filename gets disguised.
    sendTo($workspace, $channel, "ship it\u{202E}gnitset")->assertCreated();

    expect(Message::first()->body)->toBe('ship itgnitset');
});

it('updates the channel activity counters', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'First.')->assertCreated();
    sendTo($workspace, $channel, 'Second.')->assertCreated();

    $channel->refresh();

    expect($channel->message_count)->toBe(2)
        ->and($channel->last_message_at)->not->toBeNull();
});

it('broadcasts the message on the channel it was posted to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Anyone about?');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($channel): bool {
        return $event->message->channel_id === $channel->id
            && $event->broadcastOn()[0]->name === 'presence-channel.'.$channel->id;
    });
});

it('carries the sender in the broadcast payload', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Hello.');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($member): bool {
        // A missing sender would render as an anonymous message for every
        // recipient while looking perfectly correct to its author.
        return $event->broadcastWith()['message']['sender']['id'] === $member->id;
    });
});

it('never puts per-viewer permissions in a broadcast payload', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $this->actingAs($member);

    sendTo($workspace, $channel, 'Hello.');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
        // One payload reaches every subscriber, so "may I edit this?" cannot be
        // answered inside it — shipping the author's rights would tell everyone
        // else they hold them too.
        return ! array_key_exists('can', $event->broadcastWith()['message']);
    });
});

it('rejects an unauthenticated send', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);

    sendTo($workspace, $channel, 'Hello.')->assertUnauthorized();
});
