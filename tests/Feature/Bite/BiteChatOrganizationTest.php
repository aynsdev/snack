<?php

use App\Actions\Bite\StartBiteChat;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\BiteBot;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->colleague = joinWorkspace($this->workspace);
    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
    $this->chat = app(StartBiteChat::class)->handle($this->workspace, $this->owner, 'My chat');
    $this->message = makeConversationMessage($this->chat, $this->owner, 'Shared question');
});

it('renames and pins a chat and puts pinned chats before recent ones', function () {
    $recent = app(StartBiteChat::class)->handle($this->workspace, $this->owner, 'Recent chat');
    $this->chat->forceFill(['last_message_at' => now()->subDay()])->save();
    $recent->forceFill(['last_message_at' => now()])->save();

    $this->actingAs($this->owner)->patch(route('bite.chats.update', [$this->workspace->slug, $this->chat]), [
        'name' => 'Launch ideas', 'is_pinned' => true,
    ])->assertRedirect();

    expect($this->chat->fresh()->name)->toBe('Launch ideas')->and($this->chat->fresh()->is_pinned)->toBeTrue();
    $this->get(route('bite.index', $this->workspace->slug))->assertInertia(fn (Assert $page) => $page
        ->where('biteChats.0.id', $this->chat->public_id)
        ->where('biteChats.0.is_pinned', true));

    $this->patch(route('bite.chats.update', [$this->workspace->slug, $this->chat]), ['is_pinned' => false])->assertRedirect();
    $this->get(route('bite.index', $this->workspace->slug))->assertInertia(fn (Assert $page) => $page->where('biteChats.0.id', $recent->public_id));
});

it('archives and restores chats without deleting their history', function () {
    $this->actingAs($this->owner)->patch(route('bite.chats.update', [$this->workspace->slug, $this->chat]), ['is_archived' => true])->assertRedirect();
    $this->get(route('bite.index', $this->workspace->slug))->assertInertia(fn (Assert $page) => $page->has('biteChats', 0));
    $this->get(route('bite.index', [$this->workspace->slug, 'archived' => 1]))->assertInertia(fn (Assert $page) => $page
        ->where('biteArchived', true)->has('biteChats', 1)->where('biteChats.0.is_archived', true));
    $this->get(route('bite.chats.show', [$this->workspace->slug, $this->chat]))->assertInertia(fn (Assert $page) => $page->where('canPost', false));
    $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $this->chat]), ['body' => 'More'])->assertForbidden();

    $this->patch(route('bite.chats.update', [$this->workspace->slug, $this->chat]), ['is_archived' => false])->assertRedirect();
    $this->get(route('bite.chats.show', [$this->workspace->slug, $this->chat]))->assertInertia(fn (Assert $page) => $page->where('canPost', true));
    expect($this->chat->messages()->count())->toBe(1);
});

it('does not let a colleague organize or share someone else’s chat', function () {
    $this->actingAs($this->colleague)->patch(route('bite.chats.update', [$this->workspace->slug, $this->chat]), ['is_pinned' => true, 'is_archived' => true])->assertForbidden();
    $this->postJson(route('bite.chats.share.store', [$this->workspace->slug, $this->chat]))->assertForbidden();
    $this->deleteJson(route('bite.chats.share.destroy', [$this->workspace->slug, $this->chat]))->assertForbidden();
});

it('shares a read-only transcript with workspace members and excludes future messages', function () {
    $this->actingAs($this->owner)->postJson(route('bite.chats.share.store', [$this->workspace->slug, $this->chat]))->assertOk()->assertJsonStructure(['url']);
    $token = $this->chat->fresh()->share_token;
    makeConversationMessage($this->chat, $this->owner, 'Keep this future message private');

    $this->actingAs($this->colleague)->get(route('bite.shared.show', [$this->workspace->slug, $token]))->assertInertia(fn (Assert $page) => $page
        ->component('bite/shared')
        ->has('history.messages', 1)
        ->where('history.messages.0.body', 'Shared question')
        ->where('history.messages.0.can.delete', false)
        ->where('history.messages.0.can.update', false));
    $this->get(route('bite.chats.show', [$this->workspace->slug, $this->chat]))->assertNotFound();
    $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $this->chat]), ['body' => 'Intrusion'])->assertNotFound();
    $this->getJson(route('bite.shared.show', [$this->workspace->slug, $token, 'before' => PHP_INT_MAX]))
        ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Shared question');

    $this->actingAs(User::factory()->create())->get(route('bite.shared.show', [$this->workspace->slug, $token]))->assertNotFound();
});

it('paginates shared history inside its original sharing boundary', function () {
    config()->set('snack.messages.page_size', 2);
    makeConversationMessage($this->chat, $this->owner, 'Second');
    makeConversationMessage($this->chat, $this->bot, 'Third');
    $this->actingAs($this->owner)->postJson(route('bite.chats.share.store', [$this->workspace->slug, $this->chat]))->assertOk();
    $token = $this->chat->fresh()->share_token;
    makeConversationMessage($this->chat, $this->owner, 'Private fourth');
    $page = $this->actingAs($this->colleague)->getJson(route('bite.shared.show', [$this->workspace->slug, $token]))
        ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('has_more', true);
    $this->getJson(route('bite.shared.show', [$this->workspace->slug, $token, 'before' => $page->json('next_cursor')]))
        ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Shared question');
});

it('allows shared files only within the transcript and revokes their access with the link', function () {
    Storage::fake('local');
    Storage::disk('local')->put('shared.txt', 'Shared bytes');
    $file = MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id, 'message_id' => $this->message->id,
        'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => 'shared.txt', 'mime_type' => 'text/plain',
    ]);
    $this->message->update(['has_attachments' => true]);
    $this->actingAs($this->owner)->postJson(route('bite.chats.share.store', [$this->workspace->slug, $this->chat]))->assertOk();
    $token = $this->chat->fresh()->share_token;
    $history = $this->actingAs($this->colleague)->getJson(route('bite.shared.show', [$this->workspace->slug, $token]))->assertOk();
    $fileUrl = $history->json('messages.0.attachments.0.download_url');
    $this->get($fileUrl)->assertOk();
    $ordinaryUrl = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['attachment' => $file->id], absolute: false);
    $this->get($ordinaryUrl)->assertNotFound();

    $later = makeConversationMessage($this->chat, $this->owner, 'Not shared');
    $privateFile = MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id, 'message_id' => $later->id,
        'uploaded_by' => $this->owner->id, 'disk' => 'local', 'path' => 'shared.txt',
    ]);
    $laterUrl = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['attachment' => $privateFile->id, 'share' => $token], absolute: false);
    $this->get($laterUrl)->assertNotFound();

    $this->actingAs($this->owner)->deleteJson(route('bite.chats.share.destroy', [$this->workspace->slug, $this->chat]))->assertOk();
    $this->actingAs($this->colleague)->get($fileUrl)->assertNotFound();
    $this->get(route('bite.shared.show', [$this->workspace->slug, $token]))->assertNotFound();
});

it('deleting a chat also disables its shared link', function () {
    $this->actingAs($this->owner)->postJson(route('bite.chats.share.store', [$this->workspace->slug, $this->chat]))->assertOk();
    $url = route('bite.shared.show', [$this->workspace->slug, $this->chat->fresh()->share_token]);
    $this->delete(route('bite.chats.destroy', [$this->workspace->slug, $this->chat]))->assertRedirect();
    $this->actingAs($this->colleague)->get($url)->assertNotFound();
});
