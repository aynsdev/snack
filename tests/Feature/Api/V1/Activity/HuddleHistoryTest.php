<?php

/**
 * The record of huddles that have happened, over the API.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists a finished huddle from a channel the viewer is in', function () {
    $huddle = makeHuddle($this->channel, [$this->owner]);
    $huddle->update(['ended_at' => $huddle->started_at->addMinutes(5)]);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/huddles")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $huddle->public_id)
        ->assertJsonPath('data.0.is_live', false)
        ->assertJsonPath('data.0.duration_seconds', 300)
        ->assertJsonPath('data.0.container.slug', $this->channel->slug)
        ->assertJsonCount(1, 'data.0.participants');
});

it('leaves out a direct message between two other people', function () {
    $ada = joinWorkspace($this->workspace);
    $bo = joinWorkspace($this->workspace);

    makeHuddle(makeConversation($this->workspace, [$ada, $bo]), [$ada, $bo]);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/huddles")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
