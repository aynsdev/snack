<?php

use App\Actions\Bite\StartBiteChat;
use App\Ai\BiteContext;
use App\Enums\ConversationType;
use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BiteGeneration;
use App\Models\BiteSetting;
use App\Models\Conversation;
use App\Services\BiteBot;
use App\Services\MessageHistory;
use App\Services\UnreadCounter;
use App\Services\WorkspaceNavigator;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Bite page: a person's own chats with Bite, kept apart from their DMs.
 */
beforeEach(function () {
    Queue::fake();

    config()->set('snack.bite.enabled', true);
    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
});

function startChat(string $message = 'Summarise today'): Conversation
{
    return app(StartBiteChat::class)->handle(test()->workspace, test()->owner, $message);
}

it('starts a chat from its first message and opens it', function () {
    $response = $this->actingAs($this->owner)->post(route('bite.chats.store', $this->workspace->slug), [
        'body' => "@bite Make a banner for the launch\nwith a rocket",
    ]);

    $chat = Conversation::query()->where('type', ConversationType::Bite)->sole();

    $response->assertRedirect(route('bite.chats.show', [$this->workspace->slug, $chat]));

    expect($chat->name)->toBe('Make a banner for the launch')
        ->and($chat->activeUsers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $this->bot->id])->sort()->values()->all())
        ->and($chat->messages()->sole()->body)->toContain('rocket');
});

it('lets a person keep several chats with Bite', function () {
    startChat('one');
    startChat('two');

    expect(Conversation::query()->biteChats()->count())->toBe(2);
});

it('keeps the selected model on the first message and follow-up messages', function () {
    $this->actingAs($this->owner)->post(route('bite.chats.store', $this->workspace->slug), [
        'body' => 'A launch banner',
        'bite_model' => 'recraft-4',
    ])->assertRedirect();

    $chat = Conversation::query()->biteChats()->sole();

    expect($chat->messages()->sole()->metadata['bite_model'])->toBe('recraft-4');

    $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $chat]), [
        'body' => 'Animate it',
        'bite_model' => 'veo-3.1',
    ])->assertCreated();

    expect($chat->messages()->latest('id')->first()->metadata['bite_model'])->toBe('veo-3.1');
});

it('rejects an unknown generation model before writing a message', function () {
    $this->actingAs($this->owner)->postJson(route('bite.chats.store', $this->workspace->slug), [
        'body' => 'A banner',
        'bite_model' => 'unlisted-model',
    ])->assertUnprocessable()->assertJsonValidationErrors('bite_model');

    expect(Conversation::query()->biteChats()->count())->toBe(0);

    $chat = startChat();

    $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $chat]), [
        'body' => 'A banner',
        'bite_model' => 'unlisted-model',
    ])->assertUnprocessable()->assertJsonValidationErrors('bite_model');

    expect($chat->messages()->count())->toBe(0);
});

it('does not accept generation controls on a conversation between people', function () {
    $colleague = joinWorkspace($this->workspace);
    $chat = makeConversation($this->workspace, [$this->owner, $colleague]);

    $this->actingAs($this->owner)
        ->postJson(route('conversations.messages.store', [$this->workspace->slug, $chat]), [
            'body' => 'Hello',
            'bite_model' => 'mystic',
        ])->assertUnprocessable()->assertJsonValidationErrors('bite_model');

    expect($chat->messages()->count())->toBe(0);
});

it('only offers models for enabled generation capabilities', function () {
    config()->set('services.magnific.key', 'test-key');
    BiteSetting::factory()->forWorkspace($this->workspace)->create(['capabilities' => ['generate_image']]);

    $assertModels = fn (Assert $page) => $page
        ->has('mediaModels', count(MagnificModel::forKind(MediaKind::Image)))
        ->where('mediaModels.0.id', 'mystic')
        ->where('mediaModels.0.kind', 'image');

    $this->actingAs($this->owner)->get(route('bite.index', $this->workspace->slug))->assertInertia($assertModels);
    $this->get(route('bite.chats.show', [$this->workspace->slug, startChat()]))->assertInertia($assertModels);

    config()->set('services.magnific.key', null);
    $this->get(route('bite.index', $this->workspace->slug))
        ->assertInertia(fn (Assert $page) => $page->has('mediaModels', 0));
});

it('lists only the person\'s own chats on the page', function () {
    $mine = startChat('mine');
    $colleague = joinWorkspace($this->workspace);
    app(StartBiteChat::class)->handle($this->workspace, $colleague, 'theirs');

    $this->actingAs($this->owner)
        ->get(route('bite.chats.show', [$this->workspace->slug, $mine]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('bite/show')
            ->where('chat.id', $mine->public_id)
            ->has('biteChats', 1)
            ->where('biteChats.0.title', 'mine'));
});

it('keeps another person out of a chat', function () {
    $chat = startChat();
    $colleague = joinWorkspace($this->workspace);

    $this->actingAs($colleague)
        ->get(route('bite.chats.show', [$this->workspace->slug, $chat]))
        ->assertNotFound();
});

it('shows only in-progress generations from the current chat and viewer', function () {
    $chat = startChat();
    $request = makeConversationMessage($chat, $this->owner, 'Draw a rocket');
    $attributes = [
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'request_message_id' => $request->id,
    ];

    $pending = BiteGeneration::factory()->create($attributes);
    BiteGeneration::factory()->video()->create([...$attributes, 'status' => GenerationStatus::Processing]);
    BiteGeneration::factory()->completed()->create($attributes);
    BiteGeneration::factory()->create([...$attributes, 'status' => GenerationStatus::Failed]);
    BiteGeneration::factory()->create([...$attributes, 'user_id' => joinWorkspace($this->workspace)->id]);

    $otherRequest = makeConversationMessage(startChat('Another chat'), $this->owner, 'Another rocket');
    BiteGeneration::factory()->create([...$attributes, 'request_message_id' => $otherRequest->id]);
    $threadReply = makeConversationMessage($chat, $this->owner, 'A reply');
    $threadReply->update(['parent_message_id' => $request->id]);
    BiteGeneration::factory()->create([...$attributes, 'request_message_id' => $threadReply->id]);

    $this->actingAs($this->owner)->get(route('bite.chats.show', [$this->workspace->slug, $chat]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('mediaGenerations', 2)
            ->where('mediaGenerations.0.id', $pending->id)
            ->where('mediaGenerations.0.model', 'Mystic')
            ->where('mediaGenerations.1.status', 'processing'));
});

it('updates generation status without fetching message history again', function (GenerationStatus $status) {
    $chat = startChat();
    $request = makeConversationMessage($chat, $this->owner, 'Draw a rocket');
    $generation = BiteGeneration::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'request_message_id' => $request->id,
    ]);

    $this->mock(MessageHistory::class)->shouldNotReceive('page');
    $url = route('bite.chats.show', [$this->workspace->slug, $chat]);
    $this->actingAs($this->owner)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'bite/show',
        'X-Inertia-Partial-Data' => 'mediaGenerations',
    ]);

    $this->get($url)->assertOk()->assertJsonCount(1, 'props.mediaGenerations')->assertJsonMissingPath('props.history');
    $generation->update(['status' => $status]);
    $this->get($url)->assertOk()->assertJsonCount(0, 'props.mediaGenerations');
})->with([GenerationStatus::Completed, GenerationStatus::Failed]);

it('keeps Bite chats out of the DM list, its badges and its redirect', function () {
    $chat = startChat();

    expect(app(WorkspaceNavigator::class)->sidebarConversations($this->workspace, $this->owner))->toBeEmpty();

    app(BiteBot::class)->post($chat, $this->bot, 'Here you go');

    expect(app(UnreadCounter::class)->forConversations($this->workspace, $this->owner))->toBeEmpty();

    $this->actingAs($this->owner)
        ->get(route('conversations.index', $this->workspace->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('conversations/index'));
});

it('sends a DM link to a Bite chat on to the Bite page', function () {
    $chat = startChat();

    $this->actingAs($this->owner)
        ->get(route('conversations.show', [$this->workspace->slug, $chat]))
        ->assertRedirect(route('bite.chats.show', [$this->workspace->slug, $chat]));
});

it('renames and deletes a chat for its owner only', function () {
    $chat = startChat();
    $colleague = joinWorkspace($this->workspace);

    $this->actingAs($colleague)
        ->patch(route('bite.chats.update', [$this->workspace->slug, $chat]), ['name' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->patch(route('bite.chats.update', [$this->workspace->slug, $chat]), ['name' => 'Launch assets'])
        ->assertRedirect();

    expect($chat->fresh()->name)->toBe('Launch assets')
        ->and($chat->messages()->count())->toBe(0);

    $this->actingAs($this->owner)
        ->delete(route('bite.chats.destroy', [$this->workspace->slug, $chat]))
        ->assertRedirect(route('bite.index', $this->workspace->slug));

    expect(Conversation::query()->whereKey($chat->id)->exists())->toBeFalse();
});

it('treats a Bite chat as private with Bite', function () {
    $context = new BiteContext(workspace: $this->workspace, user: $this->owner, container: startChat());

    expect($context->isPrivateWithBite())->toBeTrue();
});

it('hides the page from people Bite does not answer', function () {
    BiteSetting::factory()->forWorkspace($this->workspace)->create(['audience' => 'admins']);
    $member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->actingAs($member)->get(route('bite.index', $this->workspace->slug))->assertNotFound();
    $this->actingAs($member)->post(route('bite.chats.store', $this->workspace->slug), ['body' => 'hi'])->assertForbidden();
    $this->actingAs($this->owner)->get(route('bite.index', $this->workspace->slug))->assertOk();
});

it('lists the person\'s own generations in the Media tab', function () {
    $colleague = joinWorkspace($this->workspace);

    BiteGeneration::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'prompt' => 'mine, rendering']);
    BiteGeneration::factory()->video()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'prompt' => 'mine, video']);
    BiteGeneration::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $colleague->id]);

    $this->actingAs($this->owner)
        ->get(route('bite.media.index', $this->workspace->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->component('bite/media')
            ->has('generations.data', 2));

    $this->actingAs($this->owner)
        ->get(route('bite.media.index', [$this->workspace->slug, 'kind' => 'video']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.prompt', 'mine, video'));
});
