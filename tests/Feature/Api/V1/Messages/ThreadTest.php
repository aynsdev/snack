<?php

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);
    $this->root = makeMessage($this->channel, $this->owner, 'Root');

    $this->base = "/api/v1/w/{$this->workspace->slug}";
    $this->withToken(mobileToken($this->owner));

    $this->reply = fn (string $body) => $this->postJson(
        "{$this->base}/channels/{$this->channel->slug}/messages",
        ['body' => $body, 'parent_message_id' => $this->root->id],
    );
});

it('replies in a thread and reads it back', function () {
    $replyId = ($this->reply)('First reply')->assertCreated()->json('message.id');

    $this->getJson("{$this->base}/messages/{$this->root->id}/thread")
        ->assertOk()
        ->assertJsonPath('root.id', $this->root->id)
        ->assertJsonPath('replies.0.id', $replyId);
});

it('lists followed threads', function () {
    ($this->reply)('Snack.')->assertCreated();

    $this->getJson("{$this->base}/threads")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.root.id', $this->root->id);
});

it('marks a thread read', function () {
    $replyId = ($this->reply)('Reply')->assertCreated()->json('message.id');

    $this->postJson("{$this->base}/messages/{$this->root->id}/thread/read", ['last_read_reply_id' => $replyId])
        ->assertOk()
        ->assertJsonPath('root_id', $this->root->id)
        ->assertJsonPath('last_read_reply_id', $replyId);
});

it('follows and unfollows a thread', function () {
    $this->postJson("{$this->base}/messages/{$this->root->id}/thread/subscription")
        ->assertOk()
        ->assertJsonPath('is_subscribed', true);

    $this->deleteJson("{$this->base}/messages/{$this->root->id}/thread/subscription")
        ->assertOk()
        ->assertJsonPath('is_subscribed', false);
});

it('hides a thread from somebody outside the workspace', function () {
    [, $stranger] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($stranger))
        ->getJson("{$this->base}/messages/{$this->root->id}/thread")
        ->assertNotFound();
});
