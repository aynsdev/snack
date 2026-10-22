<?php

use App\Actions\Bite\StartBiteChat;
use App\Models\BiteGeneration;
use App\Models\BiteSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\BiteBot;
use Illuminate\Support\Facades\Queue;

/**
 * A person's own chats with Bite over the mobile API.
 */
beforeEach(function () {
    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    Queue::fake();

    config()->set('snack.bite.enabled', true);
    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->colleague = joinWorkspace($this->workspace);
    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
    $this->base = "/api/v1/w/{$this->workspace->slug}/ai";
    $this->startChat = fn (string $message = 'Summarise today'): Conversation => app(StartBiteChat::class)
        ->handle($this->workspace, $this->owner, $message);
});

it('starts a chat with its first message', function () {
    $response = ($this->as)($this->owner)
        ->postJson("{$this->base}/chats", ['body' => 'Make a banner for the launch'])
        ->assertCreated()
        ->assertJsonPath('data.chat.title', 'Make a banner for the launch')
        ->assertJsonPath('data.message.body', 'Make a banner for the launch');

    $chat = Conversation::query()->biteChats()->sole();

    expect($response->json('data.chat.id'))->toBe($chat->public_id);
});

it('lists the person\'s chats and archived chats separately', function () {
    $chat = ($this->startChat)('Launch plan');
    $archived = ($this->startChat)('Old plan');
    $archived->forceFill(['archived_at' => now()])->save();

    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(1, 'data.chats')
        ->assertJsonPath('data.chats.0.id', $chat->public_id)
        ->assertJsonPath('data.archived', false);

    $this->withToken($token)
        ->getJson("{$this->base}?archived=1")
        ->assertOk()
        ->assertJsonPath('data.chats.0.id', $archived->public_id);

    ($this->as)($this->colleague)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(0, 'data.chats');
});

it('hides the Bite page from people Bite does not answer', function () {
    BiteSetting::factory()->forWorkspace($this->workspace)->create(['audience' => 'admins']);

    ($this->as)($this->colleague)->getJson($this->base)->assertNotFound();
    ($this->as)($this->colleague)->postJson("{$this->base}/chats", ['body' => 'hi'])->assertForbidden();
});

it('opens a chat with its history', function () {
    $chat = ($this->startChat)();
    makeConversationMessage($chat, $this->owner, 'What happened today?');

    ($this->as)($this->owner)
        ->getJson("{$this->base}/chats/{$chat->public_id}")
        ->assertOk()
        ->assertJsonPath('data.chat.id', $chat->public_id)
        ->assertJsonPath('data.summary.title', 'Summarise today')
        ->assertJsonPath('data.history.messages.0.body', 'What happened today?')
        ->assertJsonPath('data.can_post', true);
});

it('sends follow-up messages through the conversation endpoints', function () {
    $chat = ($this->startChat)();

    ($this->as)($this->owner)
        ->postJson("/api/v1/w/{$this->workspace->slug}/dms/{$chat->public_id}/messages", ['body' => 'And tomorrow?'])
        ->assertCreated();

    expect($chat->messages()->count())->toBe(1);
});

it('sends a Bite chat opened as a DM on to the chat endpoint', function () {
    $chat = ($this->startChat)();

    ($this->as)($this->owner)
        ->getJson("/api/v1/w/{$this->workspace->slug}/dms/{$chat->public_id}")
        ->assertRedirect("/api/v1/w/{$this->workspace->slug}/ai/chats/{$chat->public_id}");
});

it('does not open a DM as a Bite chat, or someone else\'s chat', function () {
    $direct = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    $chat = ($this->startChat)();

    ($this->as)($this->owner)
        ->getJson("{$this->base}/chats/{$direct->public_id}")
        ->assertNotFound();

    ($this->as)($this->colleague)
        ->getJson("{$this->base}/chats/{$chat->public_id}")
        ->assertNotFound();
});

it('renames, pins and archives a chat', function () {
    $chat = ($this->startChat)();

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/chats/{$chat->public_id}", ['name' => 'Launch ideas', 'is_pinned' => true, 'is_archived' => true])
        ->assertOk()
        ->assertJsonPath('data.title', 'Launch ideas')
        ->assertJsonPath('data.is_pinned', true)
        ->assertJsonPath('data.is_archived', true);

    ($this->as)($this->colleague)
        ->patchJson("{$this->base}/chats/{$chat->public_id}", ['is_pinned' => false])
        ->assertForbidden();
});

it('deletes a chat', function () {
    $chat = ($this->startChat)();

    ($this->as)($this->colleague)
        ->deleteJson("{$this->base}/chats/{$chat->public_id}")
        ->assertForbidden();

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/chats/{$chat->public_id}")
        ->assertNoContent();

    expect(Conversation::query()->whereKey($chat->id)->exists())->toBeFalse();
});

it('lists the person\'s own generated media', function () {
    BiteGeneration::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'prompt' => 'mine, rendering']);
    BiteGeneration::factory()->video()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'prompt' => 'mine, video']);
    BiteGeneration::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->colleague->id]);

    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->getJson("{$this->base}/media")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($token)
        ->getJson("{$this->base}/media?kind=video")
        ->assertOk()
        ->assertJsonPath('kind', 'video')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.prompt', 'mine, video');

    $this->withToken($token)
        ->getJson("{$this->base}/media?kind=audio")
        ->assertUnprocessable();
});

it('shares a chat read-only with the workspace and revokes it', function () {
    $chat = ($this->startChat)();
    makeConversationMessage($chat, $this->owner, 'Shared question');

    ($this->as)($this->owner)
        ->postJson("{$this->base}/chats/{$chat->public_id}/share")
        ->assertOk()
        ->assertJsonStructure(['url']);

    $token = $chat->fresh()->share_token;
    makeConversationMessage($chat, $this->owner, 'After sharing');

    ($this->as)($this->colleague)
        ->getJson("{$this->base}/shared/{$token}")
        ->assertOk()
        ->assertJsonPath('data.shared_chat.title', 'Summarise today')
        ->assertJsonCount(1, 'data.history.messages')
        ->assertJsonPath('data.history.messages.0.body', 'Shared question');

    ($this->as)(User::factory()->create())
        ->getJson("{$this->base}/shared/{$token}")
        ->assertNotFound();

    ($this->as)($this->colleague)
        ->postJson("{$this->base}/chats/{$chat->public_id}/share")
        ->assertForbidden();

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/chats/{$chat->public_id}/share")
        ->assertOk()
        ->assertJsonPath('revoked', true);

    ($this->as)($this->colleague)
        ->getJson("{$this->base}/shared/{$token}")
        ->assertNotFound();
});
