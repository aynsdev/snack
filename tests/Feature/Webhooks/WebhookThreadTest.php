<?php

use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\WebhookThread;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $this->token = $this->webhook->token;
});

it('starts a thread on the first delivery with a thread_key', function () {
    $response = $this->postJson("/hooks/{$this->token}", [
        'text' => 'Transaction TXN-1 started',
        'thread_key' => 'TXN-1',
    ])->assertCreated();

    $root = Message::query()->sole();

    expect($root->parent_message_id)->toBeNull()
        ->and($response->json('thread_id'))->toBe($root->id)
        ->and(WebhookThread::query()->sole())
        ->key->toBe('TXN-1')
        ->message_id->toBe($root->id);
});

it('replies in that thread on every later delivery with the same thread_key', function () {
    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 started', 'thread_key' => 'TXN-1'])->assertCreated();
    $rootId = Message::query()->sole()->id;

    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 paid', 'thread_key' => 'TXN-1'])
        ->assertCreated()
        ->assertJson(['thread_id' => $rootId]);
    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 settled', 'thread_key' => 'TXN-1'])->assertCreated();

    $replies = Message::query()->where('parent_message_id', $rootId)->orderBy('id')->pluck('body');

    expect($replies->all())->toBe(['TXN-1 paid', 'TXN-1 settled'])
        ->and(Message::query()->find($rootId)->reply_count)->toBe(2)
        ->and(WebhookThread::query()->count())->toBe(1);
});

it('keeps different thread_keys in different threads', function () {
    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 started', 'thread_key' => 'TXN-1'])->assertCreated();
    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-2 started', 'thread_key' => 'TXN-2'])->assertCreated();

    expect(Message::query()->whereNull('parent_message_id')->count())->toBe(2);
});

it('does not share thread_keys between webhooks', function () {
    $other = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->postJson("/hooks/{$this->token}", ['text' => 'ours', 'thread_key' => 'TXN-1'])->assertCreated();
    $this->postJson("/hooks/{$other->token}", ['text' => 'theirs', 'thread_key' => 'TXN-1'])->assertCreated();

    expect(Message::query()->whereNull('parent_message_id')->count())->toBe(2);
});

it('starts a fresh thread when the old root was deleted', function () {
    $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 started', 'thread_key' => 'TXN-1'])->assertCreated();
    $oldRoot = Message::query()->sole();
    $oldRoot->delete();

    $response = $this->postJson("/hooks/{$this->token}", ['text' => 'TXN-1 paid', 'thread_key' => 'TXN-1'])->assertCreated();

    $newRoot = Message::query()->sole();

    expect($newRoot->parent_message_id)->toBeNull()
        ->and($response->json('thread_id'))->toBe($newRoot->id)
        ->and(WebhookThread::query()->sole()->message_id)->toBe($newRoot->id);
});

it('replies to an earlier delivery by thread_id', function () {
    $rootId = $this->postJson("/hooks/{$this->token}", ['text' => 'Build started'])->json('message_id');

    $this->postJson("/hooks/{$this->token}", ['text' => 'Build passed', 'thread_id' => $rootId])
        ->assertCreated()
        ->assertJson(['thread_id' => $rootId]);

    expect(Message::query()->latest('id')->first()->parent_message_id)->toBe($rootId);
});

it('refuses a thread_id for a message the webhook did not post', function () {
    $person = makeMessage($this->channel, $this->owner, 'A person said this');

    // Fires once first, so the hook has a bot and the check is about ownership.
    $this->postJson("/hooks/{$this->token}", ['text' => 'hello'])->assertCreated();

    $this->postJson("/hooks/{$this->token}", ['text' => 'sneaky reply', 'thread_id' => $person->id])
        ->assertUnprocessable()
        ->assertJson(['ok' => false]);

    expect($person->fresh()->reply_count)->toBe(0);
});

it('refuses a thread_id from another webhook', function () {
    $other = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $theirsId = $this->postJson("/hooks/{$other->token}", ['text' => 'theirs'])->json('message_id');

    $this->postJson("/hooks/{$this->token}", ['text' => 'reply', 'thread_id' => $theirsId])
        ->assertUnprocessable();
});

it('refuses thread_key and thread_id together', function () {
    $this->postJson("/hooks/{$this->token}", ['text' => 'x', 'thread_key' => 'TXN-1', 'thread_id' => 1])
        ->assertJsonValidationErrors('thread_key');
});
