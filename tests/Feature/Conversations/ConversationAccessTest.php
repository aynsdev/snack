<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

/**
 * Who can open a conversation.
 *
 * Conversations have no public variant: participation is the only way in. Every
 * denial for a non-participant is therefore a 404 rather than a 403 — a 403 on a
 * conversation id would confirm both that the thread exists and, by implication,
 * that particular people are talking to each other.
 */
it('lets a participant open their conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($ada)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('conversations/show')
            ->where('conversation.id', $conversation->public_id)
            ->where('canPost', true),
        );
});

it('hides a conversation from a workspace member who is not in it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $nosy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->actingAs($nosy)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertNotFound();
});

it('hides a conversation from someone in another workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    // Addressed through the outsider's *own* workspace, which is the shape an
    // attacker would actually try: a valid tenant they belong to, plus an id
    // from somewhere else. The scoped binding is what refuses it.
    $this->actingAs($outsider)
        ->get(route('conversations.show', ['workspace' => $otherWorkspace->slug, 'conversation' => $conversation]))
        ->assertNotFound();
});

it('refuses a conversation id addressed through the wrong workspace slug', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    [$otherWorkspace] = makeWorkspaceWithOwner();
    joinWorkspace($otherWorkspace, $ada);

    $this->actingAs($ada)
        ->get(route('conversations.show', ['workspace' => $otherWorkspace->slug, 'conversation' => $conversation]))
        ->assertNotFound();
});

it('lets someone who left a group read the history but not post', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    $conversation->participants()->where('user_id', $cy->id)->update(['left_at' => now()]);

    $this->actingAs($cy)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Read access survives leaving — the history they were part of is
            // still theirs — but the composer is gone.
            ->where('canPost', false)
            ->where('conversation.has_left', true),
        );
});

it('titles a direct conversation by the other person, for each side', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace, User::factory()->create(['name' => 'Ada Lovelace']));
    $bo = joinWorkspace($workspace, User::factory()->create(['name' => 'Bo Diddley']));
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $url = route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]);

    // The same row, two different titles. This is exactly why no broadcast ever
    // carries a conversation's display name.
    $this->actingAs($ada)->get($url)
        ->assertInertia(fn ($page) => $page->where('conversation.display_name', 'Bo Diddley'));

    $this->actingAs($bo)->get($url)
        ->assertInertia(fn ($page) => $page->where('conversation.display_name', 'Ada Lovelace'));
});

it('titles a named group by its name', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy], 'Launch plans');

    $this->actingAs($ada)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertInertia(fn ($page) => $page->where('conversation.display_name', 'Launch plans'));
});

it('lists a participant’s conversations in the sidebar payload', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);

    $mine = makeConversation($workspace, [$ada, $bo]);
    $theirs = makeConversation($workspace, [$bo, $cy]);

    $this->actingAs($ada)
        ->get(route('workspaces.show', ['workspace' => $workspace->slug]))
        ->assertInertia(function ($page) use ($mine, $theirs) {
            $ids = array_column($page->toArray()['props']['snack']['current']['conversations'], 'id');

            // The opaque id, never the primary key.
            expect($ids)->toContain($mine->public_id)
                ->and($ids)->not->toContain($theirs->public_id);
        });
});

it('drops a conversation from the sidebar once you leave it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $cy = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo, $cy]);

    ConversationParticipant::query()
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $ada->id)
        ->update(['left_at' => now()]);

    $this->actingAs($ada)
        ->get(route('workspaces.show', ['workspace' => $workspace->slug]))
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['snack']['current']['conversations'])->toBe([]);
        });
});

it('rejects an unauthenticated request for a conversation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $this->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->assertRedirect(route('login'));
});

it('404s an id that is not a conversation at all', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => 'not-a-real-conversation']))
        ->assertNotFound();

    expect(Conversation::count())->toBe(0);
});

it('never puts the conversation primary key in a payload', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);
    makeConversationMessage($conversation, $bo, 'Hello.');

    $props = $this->actingAs($ada)
        ->get(route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]))
        ->inertiaProps();

    /*
     * A sequential id in a URL advertises that its neighbours exist and lets
     * anyone measure how many private conversations the deployment holds. Every
     * such request is refused by the policy, but the count is a fact worth not
     * publishing — so the primary key stays on the server entirely.
     */
    expect($props['conversation']['id'])->toBe($conversation->public_id)
        ->and($props['conversation']['id'])->not->toBe($conversation->id)
        ->and($props['history']['messages'][0]['conversation_id'])->toBe($conversation->public_id);

    // Belt and braces: the integer must not appear anywhere in the serialized
    // conversation, under any key.
    expect(json_encode($props['conversation']))
        ->not->toContain('"'.$conversation->id.'"');
});

it('addresses a conversation by an unguessable id', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $ada = joinWorkspace($workspace);
    $bo = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$ada, $bo]);

    $url = route('conversations.show', ['workspace' => $workspace->slug, 'conversation' => $conversation]);

    expect($url)->toContain($conversation->public_id)
        ->and($conversation->public_id)->toHaveLength(26);

    // The primary key is not an address any more.
    $this->actingAs($ada)
        ->get(route('conversations.show', [
            'workspace' => $workspace->slug,
            'conversation' => (string) $conversation->id,
        ]))
        ->assertNotFound();
});
