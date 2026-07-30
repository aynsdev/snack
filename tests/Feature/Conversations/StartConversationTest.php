<?php

use App\Enums\ConversationType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Events\ConversationStarted;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Opening a conversation.
 *
 * `participant_ids` is the only place in the DM feature where the client names
 * people rather than a record the route already resolved, so most of what is
 * asserted here is that those ids are not trusted.
 */
function startConversation(Workspace $workspace, array $participantIds, ?string $name = null): TestResponse
{
    return test()->post(
        route('conversations.store', ['workspace' => $workspace->slug]),
        array_filter([
            'participant_ids' => $participantIds,
            'name' => $name,
        ], fn (mixed $value): bool => $value !== null),
    );
}

it('opens a direct conversation with a colleague', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id])->assertRedirect();

    $conversation = Conversation::sole();

    expect($conversation->type)->toBe(ConversationType::Direct)
        ->and($conversation->workspace_id)->toBe($workspace->id)
        ->and($conversation->name)->toBeNull()
        ->and($conversation->participants()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$ada->id, $bo->id])->sort()->values()->all());
});

it('returns the same conversation when the same pair is messaged again', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $this->actingAs($ada);
    startConversation($workspace, [$bo->id])->assertRedirect();

    // From the other side this time: the pair is the same set, so it must be
    // the same thread. Two rows here would fragment the history in half.
    $this->actingAs($bo);
    startConversation($workspace, [$ada->id])->assertRedirect();

    expect(Conversation::count())->toBe(1);
});

it('gives the same pair separate conversations in separate workspaces', function () {
    [$first] = makeWorkspaceWithOwner();
    [$second] = makeWorkspaceWithOwner();

    $ada = User::factory()->create();
    $bo = User::factory()->create();

    foreach ([$first, $second] as $workspace) {
        joinWorkspace($workspace, $ada);
        joinWorkspace($workspace, $bo);
    }

    $this->actingAs($ada);

    startConversation($first, [$bo->id])->assertRedirect();
    startConversation($second, [$bo->id])->assertRedirect();

    // Uniqueness is per workspace. Sharing one thread across tenants would leak
    // one customer's conversation into another's sidebar.
    expect(Conversation::count())->toBe(2);
});

it('opens a group conversation for three or more people', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id, $cy->id])->assertRedirect();

    $conversation = Conversation::sole();

    expect($conversation->type)->toBe(ConversationType::Group)
        ->and($conversation->participants()->count())->toBe(3);
});

it('keeps a named group separate from the unnamed one with the same people', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id, $cy->id])->assertRedirect();
    startConversation($workspace, [$bo->id, $cy->id], 'Launch plans')->assertRedirect();

    // The name is the thing that distinguishes it from whatever else those
    // people already share, so naming one always makes a new thread.
    expect(Conversation::count())->toBe(2)
        ->and(Conversation::whereNotNull('name')->sole()->name)->toBe('Launch plans');
});

it('never names a direct conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id], 'Just us')->assertRedirect();

    // A CHECK constraint refuses a named direct conversation, so the action
    // drops the name rather than letting the database reject the insert.
    expect(Conversation::sole()->name)->toBeNull();
});

it('refuses to open a conversation with someone from another workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    $this->actingAs($member);

    startConversation($workspace, [$outsider->id])
        ->assertSessionHasErrors('participant_ids.0');

    expect(Conversation::count())->toBe(0);
});

it('refuses to open a conversation with a user who is in no workspace at all', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $stranger = User::factory()->create();

    $this->actingAs($member);

    startConversation($workspace, [$stranger->id])
        ->assertSessionHasErrors('participant_ids.0');
});

it('refuses to open a conversation with a suspended member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $suspended = joinWorkspace($workspace, status: MemberStatus::Suspended);

    $this->actingAs($member);

    // A suspended member is treated exactly like a non-member everywhere else,
    // and starting a DM with them is no exception.
    startConversation($workspace, [$suspended->id])
        ->assertSessionHasErrors('participant_ids.0');
});

it('refuses a conversation whose only participant is yourself', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member);

    startConversation($workspace, [$member->id])
        ->assertSessionHasErrors('participant_ids');

    expect(Conversation::count())->toBe(0);
});

it('stops a guest from starting a conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $member = joinWorkspace($workspace);

    $this->actingAs($guest);

    // Guests are confined to the channels they were added to; they cannot open
    // a private line to anyone in the company.
    startConversation($workspace, [$member->id])->assertForbidden();

    expect(Conversation::count())->toBe(0);
});

it('tells the other participants that a conversation was started', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id]);

    Event::assertDispatched(ConversationStarted::class, function (ConversationStarted $event) use ($ada, $bo): bool {
        $channels = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());

        // Each participant hears about it on their own private channel, which is
        // the only one they are guaranteed to be subscribed to while reading
        // something else entirely.
        return in_array('private-user.'.$ada->id, $channels, true)
            && in_array('private-user.'.$bo->id, $channels, true);
    });
});

it('never puts message content in the started broadcast', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);

    $this->actingAs($ada);

    startConversation($workspace, [$bo->id]);

    Event::assertDispatched(ConversationStarted::class, function (ConversationStarted $event): bool {
        return array_keys($event->broadcastWith()) === ['conversation_id'];
    });
});

it('rejects an unauthenticated attempt to start a conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    startConversation($workspace, [$member->id])->assertRedirect(route('login'));
});
