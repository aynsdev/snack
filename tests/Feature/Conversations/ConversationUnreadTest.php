<?php

use App\Enums\NotificationLevel;
use App\Events\ConversationRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Unread state for conversations.
 *
 * Read position is a single pointer on the participant row — never a row per user
 * per message — so everything here is really asserting one thing: that "unread"
 * is derived by counting ids above that pointer, and that the pointer only ever
 * moves forward.
 */
function conversationUnreadFor(Conversation $conversation, Workspace $workspace): array
{
    $response = test()->get(route('workspaces.show', ['workspace' => $workspace->slug]));

    $unread = $response->inertiaProps('snack')['current']['unread']['conversations'];

    return (array) ($unread[$conversation->public_id] ?? ['messages' => 0, 'mentions' => 0]);
}

function markConversationRead(Conversation $conversation, ?int $messageId = null): TestResponse
{
    return test()->postJson(
        route('conversations.read.store', [
            'workspace' => $conversation->workspace->slug,
            'conversation' => $conversation,
        ]),
        $messageId === null ? [] : ['last_read_message_id' => $messageId],
    );
}

it('counts messages from other people as unread', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    makeConversationMessage($conversation, $bo, 'One.');
    makeConversationMessage($conversation, $bo, 'Two.');

    $this->actingAs($ada);

    expect(conversationUnreadFor($conversation, $workspace)['messages'])->toBe(2);
});

it('does not count your own messages as unread', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    makeConversationMessage($conversation, $ada, 'Mine.');
    makeConversationMessage($conversation, $bo, 'Theirs.');

    $this->actingAs($ada);

    // Without this the conversation would light up the instant you posted in it.
    expect(conversationUnreadFor($conversation, $workspace)['messages'])->toBe(1);
});

it('does not count a system notice as unread', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    Message::factory()
        ->inConversation($conversation)
        ->system('Bo named the conversation "Launch".')
        ->create();

    $this->actingAs($ada);

    // Snack talking about itself is not somebody messaging you.
    expect(conversationUnreadFor($conversation, $workspace)['messages'])->toBe(0);
});

it('treats every unread conversation message as a mention', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    makeConversationMessage($conversation, $bo, 'Directly to you.');

    $this->actingAs($ada);

    // Everything said in a conversation you are in is addressed to you, so the
    // sidebar is entitled to be as loud about it as it is about an @-mention.
    expect(conversationUnreadFor($conversation, $workspace))->toBe(['messages' => 1, 'mentions' => 1]);
});

it('clears the badge once the reader reaches the bottom', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    makeConversationMessage($conversation, $bo, 'One.');
    $newest = makeConversationMessage($conversation, $bo, 'Two.');

    $this->actingAs($ada);

    markConversationRead($conversation, $newest->id)
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $newest->id);

    expect(conversationUnreadFor($conversation, $workspace)['messages'])->toBe(0);
});

it('marks everything read when no message is named', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    makeConversationMessage($conversation, $bo, 'One.');
    $newest = makeConversationMessage($conversation, $bo, 'Two.');

    $this->actingAs($ada);

    markConversationRead($conversation)->assertOk();

    expect($conversation->participants()->where('user_id', $ada->id)->value('last_read_message_id'))
        ->toBe($newest->id);
});

it('never moves the read pointer backwards', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $older = makeConversationMessage($conversation, $bo, 'One.');
    $newer = makeConversationMessage($conversation, $bo, 'Two.');

    $this->actingAs($ada);

    markConversationRead($conversation, $newer->id)->assertOk();

    // A tab left open on older history reports a stale position when it finally
    // syncs; honouring it would resurrect a badge the reader already cleared.
    markConversationRead($conversation, $older->id)
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $newer->id);
});

it('refuses a read pointer naming a message from another conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);

    $mine = makeConversation($workspace, [$ada, $bo]);
    $theirs = makeConversation($workspace, [$bo, $cy]);
    $foreign = makeConversationMessage($theirs, $bo, 'Not yours.');

    $this->actingAs($ada);

    // Not a leak on its own, but parking a pointer on a foreign id would corrupt
    // this participant row's unread arithmetic for the rest of its life.
    markConversationRead($mine, $foreign->id)->assertJsonValidationErrorFor('last_read_message_id');
});

it('refuses to advance the read pointer of a conversation the user is not in', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($nosy);

    markConversationRead($conversation)->assertNotFound();
});

it('syncs the read position to the user’s own devices and nobody else’s', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);
    $message = makeConversationMessage($conversation, $bo, 'Hello.');

    $this->actingAs($ada);
    markConversationRead($conversation, $message->id);

    Event::assertDispatched(ConversationRead::class, function (ConversationRead $event) use ($ada): bool {
        // "Seen at 11:04" is a social obligation Snack deliberately does not
        // create, so this reaches only the reader's own channel.
        return array_map(fn ($channel): string => $channel->name, $event->broadcastOn())
            === ['private-user.'.$ada->id];
    });
});

it('leaves unread state alone for a conversation the user left', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $conversation->participants()->where('user_id', $cy->id)->update(['left_at' => now()]);
    makeConversationMessage($conversation, $bo, 'Carrying on without them.');

    $this->actingAs($cy);

    expect(conversationUnreadFor($conversation, $workspace)['messages'])->toBe(0);
});

it('mutes a conversation for one participant only', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada)
        ->patch(route('conversations.preferences.update', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]), ['is_muted' => true])
        ->assertRedirect();

    expect($conversation->participants()->where('user_id', $ada->id)->value('is_muted'))->toBeTrue()
        // Silencing a busy group must not silence it for everyone in it.
        ->and($conversation->participants()->where('user_id', $bo->id)->value('is_muted'))->toBeFalse();
});

it('changes the notification level without resetting the mute flag', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $conversation->participants()->where('user_id', $ada->id)->update(['is_muted' => true]);

    $this->actingAs($ada)
        ->patch(route('conversations.preferences.update', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]), ['notification_level' => NotificationLevel::Mentions->value])
        ->assertRedirect();

    $participant = $conversation->participants()->where('user_id', $ada->id)->sole();

    expect($participant->notification_level)->toBe(NotificationLevel::Mentions)
        // A request that only sets the level must not quietly unmute.
        ->and($participant->is_muted)->toBeTrue();
});

it('refuses to change preferences for a conversation the user is not in', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($nosy)
        ->patch(route('conversations.preferences.update', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]), ['is_muted' => true])
        ->assertNotFound();
});
