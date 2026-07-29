<?php

use App\Enums\ChannelType;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cursor-paginated history.
 *
 * The properties that matter: a page never depends on how much has been posted
 * since, the whole history is never loaded, and reading is gated by the same
 * policy as viewing the channel.
 */
function historyUrl($workspace, $channel, ?int $before = null): string
{
    return route('channels.messages.index', array_filter([
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
        'before' => $before,
    ]));
}

it('returns the newest page of history, oldest first', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    foreach (range(1, 5) as $n) {
        makeMessage($channel, $owner, "message {$n}");
    }

    $response = $this->actingAs($owner)->getJson(historyUrl($workspace, $channel));

    $response->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null)
        ->assertJsonCount(5, 'messages')
        // Ascending, so the client renders or prepends the array as it stands.
        ->assertJsonPath('messages.0.body', 'message 1')
        ->assertJsonPath('messages.4.body', 'message 5');
});

it('caps a page at the configured size and reports the cursor for the next one', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.messages.page_size', 10);

    Message::factory()->count(25)->inChannel($channel)->from($owner)->create();

    $response = $this->actingAs($owner)->getJson(historyUrl($workspace, $channel));

    $response->assertOk()
        ->assertJsonCount(10, 'messages')
        ->assertJsonPath('has_more', true);

    // The cursor is the oldest id on this page: the boundary to walk back from.
    $oldestOnPage = $response->json('messages.0.id');

    expect($response->json('next_cursor'))->toBe($oldestOnPage);
});

it('walks backwards through the whole history without repeating or skipping', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.messages.page_size', 10);

    foreach (range(1, 25) as $n) {
        makeMessage($channel, $owner, "message {$n}");
    }

    $this->actingAs($owner);

    $seen = [];
    $cursor = null;

    do {
        $page = $this->getJson(historyUrl($workspace, $channel, $cursor))->assertOk();
        $seen = [...collect($page->json('messages'))->pluck('id')->all(), ...$seen];
        $cursor = $page->json('next_cursor');
    } while ($cursor !== null);

    expect($seen)->toHaveCount(25)
        ->and($seen)->toBe(array_unique($seen))
        ->and($seen)->toBe(collect($seen)->sort()->values()->all());
});

it('is unaffected by messages posted while the reader scrolls', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.messages.page_size', 5);

    foreach (range(1, 10) as $n) {
        makeMessage($channel, $owner, "message {$n}");
    }

    $this->actingAs($owner);

    $first = $this->getJson(historyUrl($workspace, $channel))->assertOk();
    $cursor = $first->json('next_cursor');

    // Ten more arrive between the two requests. Under offset pagination this
    // would shift every row and hand back messages the reader already has.
    foreach (range(11, 20) as $n) {
        makeMessage($channel, $owner, "message {$n}");
    }

    $second = $this->getJson(historyUrl($workspace, $channel, $cursor))->assertOk();

    expect(collect($second->json('messages'))->pluck('body')->all())
        ->toBe(['message 1', 'message 2', 'message 3', 'message 4', 'message 5']);
});

it('omits deleted messages', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'kept');
    makeMessage($channel, $owner, 'removed')->delete();

    $this->actingAs($owner)
        ->getJson(historyUrl($workspace, $channel))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', 'kept');
});

it('keeps thread replies out of the channel timeline', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $root = makeMessage($channel, $owner, 'the question');
    Message::factory()->replyTo($root)->from($owner)->create();

    // Replies belong to the thread panel; showing them inline would duplicate
    // every one of them in the main history.
    $this->actingAs($owner)
        ->getJson(historyUrl($workspace, $channel))
        ->assertOk()
        ->assertJsonCount(1, 'messages');
});

it('never leaks another channel history through the cursor', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    $other = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($other, $owner);

    makeMessage($channel, $owner, 'ours');
    $theirs = makeMessage($other, $owner, 'theirs');

    // A cursor from a different channel is just an integer; the channel filter
    // is what confines the query, not the cursor.
    $this->actingAs($owner)
        ->getJson(historyUrl($workspace, $channel, $theirs->id + 1))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', 'ours');
});

it('refuses history for a private channel the user is not in', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($member)
        ->getJson(historyUrl($workspace, $channel))
        ->assertNotFound();
});

it('lets a member preview history in a public channel they have not joined', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'come on in');

    // Reading is broader than posting: previewing is how someone decides
    // whether to join.
    $this->actingAs($member)
        ->getJson(historyUrl($workspace, $channel))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.can.update', false);
});

it('loads a page of history in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $queriesFor = function (int $messages) use ($workspace, $channel, $owner): int {
        $channel->messages()->forceDelete();
        Message::factory()->count($messages)->inChannel($channel)->from($owner)->create();

        // A fresh instance each time. The authenticated model persists across
        // requests within a test and keeps whatever relations it loaded, which
        // would make the second measurement cheaper for reasons that have
        // nothing to do with page size.
        $this->actingAs(User::findOrFail($owner->id));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(historyUrl($workspace, $channel))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    /*
     * The invariant is that page size does not affect query count — asserting a
     * fixed ceiling instead would either be too loose to catch a regression or
     * too tight to survive an unrelated change.
     *
     * The N+1 this guards against is not the obvious one. Serializing a message
     * asks the policy what the viewer may do with it, and the policy answers by
     * consulting the message's container; without a shared, pre-hydrated channel
     * that is several extra queries per row.
     */
    expect($queriesFor(30))->toBe($queriesFor(3));
});
