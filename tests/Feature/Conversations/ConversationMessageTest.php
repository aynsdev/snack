<?php

use App\Enums\MessageType;
use App\Events\ConversationActivity;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Sending and reading messages in a conversation.
 *
 * The write path is shared with channels, so rather than restating every rule
 * proven in SendMessageTest, this covers what is specific to a DM: that the
 * container cannot be crossed, and that the people *not* looking at the thread
 * still find out something arrived.
 */
function sendToConversation(Workspace $workspace, Conversation $conversation, string $body): TestResponse
{
    return test()->postJson(
        route('conversations.messages.store', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]),
        ['body' => $body],
    );
}

it('posts a message to a conversation the user is in', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);

    sendToConversation($workspace, $conversation, 'Lunch?')
        ->assertCreated()
        ->assertJsonPath('message.body', 'Lunch?')
        ->assertJsonPath('message.conversation_id', $conversation->public_id)
        ->assertJsonPath('message.channel_id', null)
        ->assertJsonPath('message.sender.id', $ada->id);

    expect(Message::sole()->type)->toBe(MessageType::Text);
});

it('derives the workspace from the conversation rather than the request', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);

    sendToConversation($workspace, $conversation, 'Hello.')->assertCreated();

    expect(Message::sole()->workspace_id)->toBe($workspace->id);
});

it('stamps the conversation as active so it sorts to the top of the sidebar', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    expect($conversation->last_message_at)->toBeNull();

    $this->actingAs($ada);
    sendToConversation($workspace, $conversation, 'Hello.')->assertCreated();

    expect($conversation->refresh()->last_message_at)->not->toBeNull();
});

it('refuses to post into a conversation the user is not in', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($nosy);

    // 404, not 403: a conversation does not confirm its own existence to
    // someone who is not in it.
    sendToConversation($workspace, $conversation, 'What are you two saying?')->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('refuses to post after leaving a group', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $conversation->participants()->where('user_id', $cy->id)->update(['left_at' => now()]);

    $this->actingAs($cy);

    // 403 rather than 404 here: they can still read the thread, so pretending it
    // does not exist would be a lie they can already disprove.
    sendToConversation($workspace, $conversation, 'Still here.')->assertForbidden();
});

it('rejects an empty message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);

    sendToConversation($workspace, $conversation, '   ')->assertJsonValidationErrorFor('body');
});

it('broadcasts the message on the conversation channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);
    sendToConversation($workspace, $conversation, 'Hello.');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversation): bool {
        return $event->message->conversation_id === $conversation->id
            && $event->broadcastOn()[0]->name === 'presence-conversation.'.$conversation->public_id;
    });
});

it('tells the other participants a message arrived, without sending its contents', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);
    sendToConversation($workspace, $conversation, 'A secret.');

    Event::assertDispatched(ConversationActivity::class, function (ConversationActivity $event) use ($ada, $bo): bool {
        $channels = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());

        return $channels === ['private-user.'.$bo->id]
            // The sender's own devices have the message already, and the author
            // has nothing to badge.
            && ! in_array('private-user.'.$ada->id, $channels, true)
            && array_keys($event->broadcastWith()) === ['conversation_id'];
    });
});

it('does not raise sidebar activity when a group is left with nobody else in it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $conversation->participants()->where('user_id', $bo->id)->update(['left_at' => now()]);

    $this->actingAs($ada);
    sendToConversation($workspace, $conversation, 'Anyone?')->assertCreated();

    // Nobody left to notify. Broadcasting to an empty recipient list would queue
    // a job that fans out to nothing.
    Event::assertNotDispatched(ConversationActivity::class);
});

it('pages conversation history with a cursor rather than loading all of it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $pageSize = (int) config('snack.messages.page_size');

    foreach (range(1, $pageSize + 5) as $index) {
        makeConversationMessage($conversation, $bo, "message {$index}");
    }

    $this->actingAs($ada);

    $first = $this->getJson(route('conversations.messages.index', [
        'workspace' => $workspace->slug,
        'conversation' => $conversation,
    ]));

    $first->assertOk()
        ->assertJsonCount($pageSize, 'messages')
        ->assertJsonPath('has_more', true);

    $older = $this->getJson(route('conversations.messages.index', [
        'workspace' => $workspace->slug,
        'conversation' => $conversation,
        'before' => $first->json('next_cursor'),
    ]));

    $older->assertOk()
        ->assertJsonCount(5, 'messages')
        ->assertJsonPath('has_more', false);
});

it('refuses conversation history to a non-participant', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);
    makeConversationMessage($conversation, $ada, 'Private.');

    $this->actingAs($nosy)
        ->getJson(route('conversations.messages.index', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]))
        ->assertNotFound();
});

it('lets a sender edit and delete their own conversation message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);
    $message = makeConversationMessage($conversation, $ada, 'Typo heer');

    $this->actingAs($ada);

    // The edit and delete routes address the message directly, so this also
    // proves MessagePolicy resolves a conversation container, not just a channel.
    $this->patchJson(route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]), [
        'body' => 'Typo here',
    ])->assertOk()->assertJsonPath('message.body', 'Typo here');

    $this->deleteJson(route('messages.destroy', ['workspace' => $workspace->slug, 'message' => $message->id]))
        ->assertOk();

    expect($message->fresh()->trashed())->toBeTrue();
});

it('stops a non-participant from touching a conversation message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);
    $message = makeConversationMessage($conversation, $ada, 'Private.');

    $this->actingAs($nosy);

    $this->patchJson(route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]), [
        'body' => 'Rewritten by a stranger',
    ])->assertNotFound();

    $this->deleteJson(route('messages.destroy', ['workspace' => $workspace->slug, 'message' => $message->id]))
        ->assertNotFound();

    expect($message->fresh()->body)->toBe('Private.');
});
