<?php

use App\Enums\ChannelType;
use App\Models\MessageAttachment;
use App\Services\MessageIndexer;

use function Pest\Laravel\actingAs;

/**
 * The queue is synchronous under test, so a message posted through the action
 * is indexed by the time the next line runs.
 */
it('finds a message by its words', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'The quarterly budget is finalized');
    makeMessage($channel, $owner, 'Lunch at one');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('search/index')
            ->has('results', 1)
            ->where('results.0.message.body', 'The quarterly budget is finalized'),
        );
});

it('stems, so a singular finds a plural', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'Three meetings this week');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'meeting']))
        ->assertInertia(fn ($page) => $page->has('results', 1));
});

it('finds a message by the name of a file attached to it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner, 'Here you go');

    MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
        // Punctuation is flattened before tokenizing, so "invoice" matches even
        // though Postgres would otherwise read the whole thing as one filename.
        'original_name' => 'Q3-invoice.pdf',
    ]);

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'invoice']))
        ->assertInertia(fn ($page) => $page->has('results', 1));
});

it('marks the matched words as segments rather than as markup', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'The quarterly budget is finalized');

    reindex();

    $response = actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget']));

    $snippet = $response->viewData('page')['props']['results'][0]['snippet'];

    expect($snippet)->not->toBeEmpty();

    $highlighted = collect($snippet)->firstWhere('highlight', true);

    expect($highlighted['text'])->toBe('budget');

    // Nothing anywhere in the payload is HTML.
    foreach ($snippet as $segment) {
        expect($segment['text'])->not->toContain('<');
    }
});

it('never returns a message from a private channel the viewer is not in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);

    makeMessage($private, $owner, 'The acquisition closes Friday');

    reindex();

    $outsider = joinWorkspace($workspace);

    actingAs($outsider)
        ->get(route('search.index', [$workspace, 'q' => 'acquisition']))
        ->assertInertia(fn ($page) => $page->has('results', 0));

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'acquisition']))
        ->assertInertia(fn ($page) => $page->has('results', 1));
});

it('never returns a message from a conversation the viewer is not in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $confidant = joinWorkspace($workspace);
    $outsider = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $confidant]);
    makeConversationMessage($conversation, $owner, 'Salary discussion');

    reindex();

    actingAs($confidant)
        ->get(route('search.index', [$workspace, 'q' => 'salary']))
        ->assertInertia(fn ($page) => $page->has('results', 1));

    actingAs($outsider)
        ->get(route('search.index', [$workspace, 'q' => 'salary']))
        ->assertInertia(fn ($page) => $page->has('results', 0));
});

it('never crosses a workspace boundary', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    makeMessage($channel, $owner, 'ours budget');

    $elsewhere = makeWorkspace();
    $theirOwner = joinWorkspace($elsewhere, $elsewhere->owner);
    $theirChannel = makeChannel($elsewhere);
    addToChannel($theirChannel, $theirOwner);
    makeMessage($theirChannel, $theirOwner, 'theirs budget');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget']))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'ours budget'),
        );
});

it('filters by author', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $other);

    makeMessage($channel, $owner, 'budget from the owner');
    makeMessage($channel, $other, 'budget from someone else');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [
            $workspace,
            'q' => "budget from:@{$other->username}",
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'budget from someone else'),
        );
});

it('filters by channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $design = makeChannel($workspace, ChannelType::Public, ['name' => 'Design']);
    $finance = makeChannel($workspace, ChannelType::Public, ['name' => 'Finance']);
    addToChannel($design, $owner);
    addToChannel($finance, $owner);

    makeMessage($design, $owner, 'budget for the rebrand');
    makeMessage($finance, $owner, 'budget for the quarter');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [
            $workspace,
            'q' => "budget in:#{$finance->slug}",
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'budget for the quarter'),
        );
});

it('filters to messages that carry files', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'budget talk, no file');
    $withFile = makeMessage($channel, $owner, 'budget attached');
    $withFile->forceFill(['has_attachments' => true])->save();

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget has:file']))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'budget attached'),
        );
});

it('filters by date, inclusive of the named day', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $old = makeMessage($channel, $owner, 'budget last year');
    $old->forceFill(['created_at' => now()->subYear()])->save();

    makeMessage($channel, $owner, 'budget today');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [
            $workspace,
            'q' => 'budget after:'.now()->subMonth()->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'budget today'),
        );

    actingAs($owner)
        ->get(route('search.index', [
            $workspace,
            'q' => 'budget before:'.now()->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page->has('results', 2));
});

it('runs a filters-only search, newest first', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeMessage($channel, $owner, 'first');
    makeMessage($channel, $owner, 'second');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => "in:#{$channel->slug}"]))
        ->assertInertia(fn ($page) => $page
            ->has('results', 2)
            ->where('results.0.message.body', 'second'),
        );
});

it('does not run an empty or one-character query', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    makeMessage($channel, $owner, 'anything at all');

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'a']))
        ->assertInertia(fn ($page) => $page
            ->has('results', 0)
            ->where('query.too_short', true),
        );
});

it('excludes a deleted message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner, 'budget retracted');

    reindex();

    $message->delete();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget']))
        ->assertInertia(fn ($page) => $page->has('results', 0));
});

it('pages results', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.search.page_size', 2);

    foreach (range(1, 3) as $index) {
        makeMessage($channel, $owner, "budget note {$index}");
    }

    reindex();

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget']))
        ->assertInertia(fn ($page) => $page
            ->has('results', 2)
            ->where('total', 3)
            ->where('has_more', true),
        );

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'budget', 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('results', 1)
            ->where('has_more', false),
        );
});

it('is not reachable from outside the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $stranger = joinWorkspace(makeWorkspace());

    actingAs($stranger)
        ->get(route('search.index', [$workspace, 'q' => 'anything']))
        ->assertNotFound();
});

it('indexes a message as it is sent, with no reindex needed', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    actingAs($owner)->postJson(
        route('channels.messages.store', [$workspace, $channel]),
        ['body' => 'The offsite is in Lisbon'],
    );

    // Deliberately no reindex() here: the send path queues the indexing job,
    // and the queue is synchronous under test.
    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'Lisbon']))
        ->assertInertia(fn ($page) => $page->has('results', 1));
});

it('reindexes a message when it is edited', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner, 'The offsite is in Lisbon');

    reindex();

    actingAs($owner)->patchJson(
        route('messages.update', [$workspace, $message]),
        ['body' => 'The offsite is in Porto'],
    );

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'Porto']))
        ->assertInertia(fn ($page) => $page->has('results', 1));

    actingAs($owner)
        ->get(route('search.index', [$workspace, 'q' => 'Lisbon']))
        ->assertInertia(fn ($page) => $page->has('results', 0));
});

/**
 * Build the search vectors for everything written so far.
 *
 * Tests post messages straight into the database through the factories, which
 * bypasses the action that would queue an indexing job — so the index is built
 * explicitly here rather than assumed.
 */
function reindex(): void
{
    app(MessageIndexer::class)->backfill(all: true);
}
