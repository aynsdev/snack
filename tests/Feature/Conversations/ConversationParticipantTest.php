<?php

use App\Enums\MessageType;
use App\Events\ConversationStarted;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Managing who is in a group conversation.
 *
 * The rules that matter here are mostly about what a *direct* conversation must
 * refuse. Its uniqueness is enforced by a partial index on the participant hash,
 * so a third person or a departure would leave a row the index can no longer
 * describe.
 */
function addPeople(Conversation $conversation, array $userIds): TestResponse
{
    return test()->post(
        route('conversations.participants.store', [
            'workspace' => $conversation->workspace->slug,
            'conversation' => $conversation,
        ]),
        ['participant_ids' => $userIds],
    );
}

it('adds someone to a group conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);

    addPeople($conversation, [$dee->id])->assertRedirect();

    expect($conversation->participants()->active()->pluck('user_id')->contains($dee->id))->toBeTrue();
});

it('refuses to add a third person to a direct message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada);

    // The UI starts a new group conversation instead. Growing this one would
    // break the uniqueness its index guarantees for the original pair.
    addPeople($conversation, [$cy->id])->assertForbidden();

    expect($conversation->participants()->count())->toBe(2);
});

it('refuses to add someone from outside the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    $this->actingAs($ada);

    addPeople($conversation, [$outsider->id])->assertSessionHasErrors('participant_ids.0');

    expect($conversation->participants()->count())->toBe(3);
});

it('refuses to add anyone on behalf of a non-participant', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($nosy);

    addPeople($conversation, [$dee->id])->assertNotFound();
});

it('treats adding an existing participant as a no-op', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);

    // Two people adding the same colleague at once is ordinary, not a conflict.
    addPeople($conversation, [$bo->id])->assertRedirect();

    expect($conversation->participants()->count())->toBe(3);
});

it('starts a newcomer at the present rather than at the beginning', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    foreach (range(1, 5) as $index) {
        makeConversationMessage($conversation, $bo, "old message {$index}");
    }

    $newest = Message::max('id');

    $this->actingAs($ada);
    addPeople($conversation, [$dee->id])->assertRedirect();

    // Left null, they would open the conversation to a badge counting five
    // messages from a discussion they were never part of.
    expect($conversation->participants()->where('user_id', $dee->id)->value('last_read_message_id'))
        ->toBe($newest);
});

it('records who was added, in the conversation itself', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada']));
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace, User::factory()->create(['name' => 'Dee']));
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);
    addPeople($conversation, [$dee->id])->assertRedirect();

    $notice = Message::where('type', MessageType::System)->sole();

    expect($notice->body)->toBe('Ada added Dee to the conversation.')
        ->and($notice->sender_id)->toBeNull()
        ->and($notice->conversation_id)->toBe($conversation->id);
});

it('does not let a system notice bump the conversation up the sidebar', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);
    addPeople($conversation, [$dee->id])->assertRedirect();

    // Being added is not somebody saying something, so it must not outrank a
    // colleague who actually did.
    expect($conversation->refresh()->last_message_at)->toBeNull();
});

it('tells the newcomer, and only the newcomer, that they were added', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);
    addPeople($conversation, [$dee->id]);

    Event::assertDispatched(ConversationStarted::class, function (ConversationStarted $event) use ($dee): bool {
        return array_map(fn ($channel): string => $channel->name, $event->broadcastOn())
            === ['private-user.'.$dee->id];
    });

    // Everyone already in it is looking at the thread, not their sidebar, so
    // they hear about it on the conversation's own channel.
    Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($conversation): bool {
        return $event->broadcastOn()[0]->name === 'presence-conversation.'.$conversation->public_id;
    });
});

it('renames a group conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada)
        ->patch(route('conversations.update', ['workspace' => $workspace->slug, 'conversation' => $conversation]), [
            'name' => 'Launch plans',
        ])
        ->assertRedirect();

    expect($conversation->refresh()->name)->toBe('Launch plans');
});

it('clears a group name to return it to being titled by its people', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy], 'Launch plans');

    $this->actingAs($ada)
        ->patch(route('conversations.update', ['workspace' => $workspace->slug, 'conversation' => $conversation]), [
            'name' => null,
        ])
        ->assertRedirect();

    expect($conversation->refresh()->name)->toBeNull();
});

it('refuses to rename a direct message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada)
        ->patch(route('conversations.update', ['workspace' => $workspace->slug, 'conversation' => $conversation]), [
            'name' => 'Just us',
        ])
        ->assertForbidden();

    expect($conversation->refresh()->name)->toBeNull();
});

it('lets someone leave a group conversation without losing the history', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);
    makeConversationMessage($conversation, $bo, 'Something worth keeping.');

    $this->actingAs($cy)
        ->delete(route('conversations.participants.destroy', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]))
        ->assertRedirect(route('workspaces.show', ['workspace' => $workspace->slug]));

    $participant = $conversation->participants()->where('user_id', $cy->id)->sole();

    // The row survives so read access, their old messages, and their read
    // position all stay coherent.
    expect($participant->left_at)->not->toBeNull();

    $this->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertOk();
});

it('refuses to leave a direct message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada)
        ->delete(route('conversations.participants.destroy', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]))
        ->assertForbidden();

    expect($conversation->participants()->whereNotNull('left_at')->count())->toBe(0);
});

it('restores a returning participant’s read position instead of resetting it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $read = makeConversationMessage($conversation, $bo, 'Read before leaving.');

    $conversation->participants()
        ->where('user_id', $cy->id)
        ->update(['last_read_message_id' => $read->id, 'left_at' => now()]);

    makeConversationMessage($conversation, $bo, 'Said while they were away.');

    $this->actingAs($ada);
    addPeople($conversation, [$cy->id])->assertRedirect();

    $participant = $conversation->participants()->where('user_id', $cy->id)->sole();

    // One row, reinstated — not a second one, which the unique index would
    // refuse anyway — so what they had already read stays read.
    expect($conversation->participants()->where('user_id', $cy->id)->count())->toBe(1)
        ->and($participant->left_at)->toBeNull()
        ->and($participant->last_read_message_id)->toBe($read->id);
});

it('keeps the participant hash describing the current group', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $dee = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $this->actingAs($ada);
    addPeople($conversation, [$dee->id])->assertRedirect();

    // A stale hash would make "open a group with these four people" find this
    // thread under its old three-person identity, or miss it entirely.
    expect($conversation->refresh()->participants_hash)
        ->toBe(Conversation::hashParticipants([$ada->id, $bo->id, $cy->id, $dee->id]));
});
