<?php

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\MessageIndexer;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);
});

function markedAgentPost(Message $message): Message
{
    $message->forceFill(['metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]])->save();

    return $message;
}

it('hides the dedupe marker from an agent post without changing what is stored', function () {
    $message = markedAgentPost(makeMessage($this->channel, $this->owner, "Plan ready\n\n‹la:45bc9c1a›"));

    expect(MessageResource::broadcastPayload($message)['body'])->toBe('Plan ready')
        ->and($message->preview())->toBe('Plan ready')
        ->and($message->fresh()->body)->toBe("Plan ready\n\n‹la:45bc9c1a›");
});

it('leaves the marker on a post a person typed', function () {
    $message = makeMessage($this->channel, $this->owner, 'Plan ready ‹la:45bc9c1a›');

    expect(MessageResource::broadcastPayload($message)['body'])->toBe('Plan ready ‹la:45bc9c1a›')
        ->and($message->preview())->toBe('Plan ready ‹la:45bc9c1a›');
});

it('only strips a marker that ends the post', function () {
    $message = markedAgentPost(makeMessage($this->channel, $this->owner, '‹la:45bc9c1a› then more'));

    expect($message->displayBody())->toBe('‹la:45bc9c1a› then more');
});

it('strips the marker from search results for an agent post', function () {
    markedAgentPost(makeMessage($this->channel, $this->owner, 'The quarterly budget is ready ‹la:45bc9c1a›'));

    app(MessageIndexer::class)->backfill(all: true);

    $response = $this->actingAs($this->owner)
        ->get(route('search.index', [$this->workspace, 'q' => 'budget']))
        ->assertOk();

    $result = $response->viewData('page')['props']['results'][0];
    $snippet = collect($result['snippet'])->pluck('text')->implode('');

    expect($result['message']['body'])->toBe('The quarterly budget is ready')
        ->and($snippet)->not->toContain('‹la:');
});
