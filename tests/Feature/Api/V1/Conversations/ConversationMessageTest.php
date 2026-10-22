<?php

use App\Models\Huddle;
use App\Models\User;

/**
 * Posting, history, the read pointer and the huddle in a conversation — the
 * web app's JSON endpoints, reached with a mobile token.
 */
beforeEach(function () {
    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->colleague = joinWorkspace($this->workspace);
    $this->conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    $this->base = "/api/v1/w/{$this->workspace->slug}/dms/{$this->conversation->public_id}";
});

it('posts a message and pages back through history', function () {
    config()->set('snack.messages.page_size', 2);

    foreach (['one', 'two', 'three'] as $body) {
        makeConversationMessage($this->conversation, $this->colleague, $body);
    }

    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->postJson("{$this->base}/messages", ['body' => 'four'])
        ->assertCreated()
        ->assertJsonPath('message.body', 'four');

    $page = $this->withToken($token)
        ->getJson("{$this->base}/messages")
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->json();

    $this->withToken($token)
        ->getJson("{$this->base}/messages?before={$page['next_cursor']}")
        ->assertOk()
        ->assertJsonPath('has_more', false);
});

it('refuses posting to someone outside the conversation', function () {
    ($this->as)(joinWorkspace($this->workspace))
        ->postJson("{$this->base}/messages", ['body' => 'hi'])
        ->assertNotFound();
});

it('moves the read pointer', function () {
    $message = makeConversationMessage($this->conversation, $this->colleague, 'read me');

    ($this->as)($this->owner)
        ->postJson("{$this->base}/read", ['last_read_message_id' => $message->id])
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $message->id);
});

it('starts and leaves the conversation huddle', function () {
    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->postJson("{$this->base}/huddle")
        ->assertOk()
        ->assertJsonPath('huddle.container.kind', 'conversation');

    expect(Huddle::query()->live()->forContainer($this->conversation)->exists())->toBeTrue();

    $this->withToken($token)
        ->deleteJson("{$this->base}/huddle")
        ->assertOk()
        ->assertJsonPath('huddle', null);
});
