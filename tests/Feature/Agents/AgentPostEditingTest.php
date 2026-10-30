<?php

use App\Models\Message;

/**
 * An agent's post is edited only by the connector that made it; its owner may
 * still delete it.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->post = makeMessage($this->channel, $this->owner, 'Working on it ‹la:abcd1234›');
    $this->post->forceFill(['metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]])->save();

    $this->actingAs($this->owner);
});

it('refuses its owner editing an agent post in the web UI', function () {
    $this->patchJson(route('messages.update', [$this->workspace->slug, $this->post->id]), ['body' => 'Rewritten'])
        ->assertForbidden();

    expect($this->post->fresh()->body)->toBe('Working on it ‹la:abcd1234›');
});

it('does not offer Edit on an agent post', function () {
    $own = makeMessage($this->channel, $this->owner, 'typed by hand');

    expect($this->owner->can('update', $this->post))->toBeFalse()
        ->and($this->owner->can('update', $own))->toBeTrue();
});

it('still lets its owner delete an agent post', function () {
    $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $this->post->id]))->assertOk();

    expect(Message::query()->find($this->post->id))->toBeNull();
});
