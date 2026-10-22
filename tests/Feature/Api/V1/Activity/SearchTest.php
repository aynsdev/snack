<?php

use App\Enums\ChannelType;
use App\Services\MessageIndexer;

/**
 * Workspace search over the API. The queue is synchronous under test; the
 * backfill makes sure everything is indexed before searching.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('finds a message by its words', function () {
    makeMessage($this->channel, $this->owner, 'The quarterly budget is finalized');
    makeMessage($this->channel, $this->owner, 'Lunch at one');

    app(MessageIndexer::class)->backfill(all: true);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/search?q=budget")
        ->assertOk()
        ->assertJsonPath('data.query.raw', 'budget')
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.page', 1)
        ->assertJsonPath('data.has_more', false)
        ->assertJsonPath('data.results.0.message.body', 'The quarterly budget is finalized')
        ->assertJsonPath('data.results.0.container.slug', $this->channel->slug);
});

it('does not find messages in a channel the searcher cannot see', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);
    $insider = joinWorkspace($this->workspace);
    addToChannel($private, $insider);

    makeMessage($private, $insider, 'Secret budget talk');

    app(MessageIndexer::class)->backfill(all: true);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/search?q=budget")
        ->assertOk()
        ->assertJsonCount(0, 'data.results');
});

it('answers an empty query without searching', function () {
    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/search")
        ->assertOk()
        ->assertJsonPath('data.query.is_empty', true)
        ->assertJsonCount(0, 'data.results');
});
