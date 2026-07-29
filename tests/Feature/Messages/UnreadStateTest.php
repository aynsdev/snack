<?php

use App\Events\ChannelRead;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Services\UnreadCounter;
use Illuminate\Support\Facades\Event;

/**
 * Unread badges and the read pointer.
 *
 * Read state is one pointer per membership, never a row per user per message.
 * These tests pin both halves of that: the arithmetic derived from the pointer,
 * and the rules that stop the pointer from being moved somewhere it should not
 * go.
 */
function readUrl($workspace, $channel): string
{
    return route('channels.read.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]);
}

function unreadFor($test, $workspace, $channel): array
{
    $page = $test->get(route('channels.show', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    $unread = $page->viewData('page')['props']['snack']['current']['unread']['channels'];

    return (array) ($unread->{$channel->id} ?? ['messages' => 0, 'mentions' => 0]);
}

it('counts messages posted since the read pointer', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    foreach (range(1, 3) as $n) {
        makeMessage($channel, $owner, "message {$n}");
    }

    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(3);
});

it('does not count the reader own messages', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $reader);

    makeMessage($channel, $reader, 'talking to myself');

    // Without this every channel would light up the instant you posted in it.
    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(0);
});

it('clears once the pointer is advanced', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    makeMessage($channel, $owner, 'unread');

    $this->actingAs($reader)->postJson(readUrl($workspace, $channel))->assertOk();

    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(0);
});

it('counts only what arrived after the pointer', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    makeMessage($channel, $owner, 'seen');
    $this->actingAs($reader)->postJson(readUrl($workspace, $channel))->assertOk();

    makeMessage($channel, $owner, 'new');
    makeMessage($channel, $owner, 'also new');

    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(2);
});

it('ignores deleted messages in the count', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    makeMessage($channel, $owner, 'kept');
    makeMessage($channel, $owner, 'removed')->delete();

    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(1);
});

it('keeps thread replies out of the channel badge', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    $root = makeMessage($channel, $owner, 'the question');
    Message::factory()->replyTo($root)->from($owner)->create();

    // Threads carry their own unread state, added in Phase 4.
    expect(unreadFor($this->actingAs($reader), $workspace, $channel)['messages'])->toBe(1);
});

it('advances the pointer to the newest message when none is named', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    makeMessage($channel, $owner, 'one');
    $newest = makeMessage($channel, $owner, 'two');

    $this->actingAs($reader)
        ->postJson(readUrl($workspace, $channel))
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $newest->id);
});

it('never moves the pointer backwards', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    $older = makeMessage($channel, $owner, 'one');
    $newer = makeMessage($channel, $owner, 'two');

    $this->actingAs($reader);
    $this->postJson(readUrl($workspace, $channel), ['last_read_message_id' => $newer->id])->assertOk();

    // A tab left open on older history reports a stale position when it finally
    // syncs; honouring it would resurrect badges the user already cleared.
    $this->postJson(readUrl($workspace, $channel), ['last_read_message_id' => $older->id])
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $newer->id);
});

it('rejects a pointer that names a message from another channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    $elsewhere = makeChannel($workspace);
    addToChannel($channel, $reader);
    addToChannel($elsewhere, $owner);

    $foreign = makeMessage($elsewhere, $owner, 'not from here');

    // Client-supplied identifiers are verified against the container before
    // they are written; a mismatched id would corrupt the arithmetic for the
    // life of the membership.
    $this->actingAs($reader)
        ->postJson(readUrl($workspace, $channel), ['last_read_message_id' => $foreign->id])
        ->assertJsonValidationErrorFor('last_read_message_id');
});

it('tells the user other devices about the new read position', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $reader);

    makeMessage($channel, $owner, 'unread');

    $this->actingAs($reader)->postJson(readUrl($workspace, $channel))->assertOk();

    Event::assertDispatched(ChannelRead::class, function (ChannelRead $event) use ($reader): bool {
        // Read position is private state, so it goes to that one user's own
        // channel and nowhere else.
        return $event->userId === $reader->id
            && $event->broadcastOn()[0]->name === 'private-user.'.$reader->id;
    });
});

it('does not create a membership for someone previewing a channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $viewer = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'preview me');

    $this->actingAs($viewer)
        ->postJson(readUrl($workspace, $channel))
        ->assertOk()
        ->assertJsonPath('last_read_message_id', null);

    expect(ChannelMember::where('user_id', $viewer->id)->exists())->toBeFalse();
});

it('counts unread across channels in one query', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $reader = joinWorkspace($workspace);

    $channels = collect(range(1, 5))->map(function () use ($workspace, $owner, $reader) {
        $channel = makeChannel($workspace);
        addToChannel($channel, $owner);
        addToChannel($channel, $reader);
        makeMessage($channel, $owner, 'unread');

        return $channel;
    });

    $unread = app(UnreadCounter::class)->forChannels($workspace, $reader);

    // One grouped query for the whole sidebar, not one per channel.
    expect($unread)->toHaveCount(5)
        ->and($unread[$channels->first()->id]['messages'])->toBe(1);
});
