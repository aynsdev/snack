<?php

use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\User;

/**
 * Direct and group conversations over the mobile API: the DM list, opening and
 * starting a thread, renaming, preferences, and who is in it.
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
    $this->base = "/api/v1/w/{$this->workspace->slug}/dms";
});

it('lists the viewer\'s conversations and the people they can message', function () {
    $conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);

    ($this->as)($this->owner)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.conversations.0.id', $conversation->public_id)
        ->assertJsonPath('data.people.0.user.id', $this->colleague->id)
        ->assertJsonPath('data.people.0.conversation.id', $conversation->public_id);
});

it('searches the directory of people to message', function () {
    $this->colleague->update(['name' => 'Zelda Pedi']);

    ($this->as)($this->owner)
        ->getJson("{$this->base}/directory?q=zelda")
        ->assertOk()
        ->assertJsonCount(1, 'people')
        ->assertJsonPath('people.0.id', $this->colleague->id);
});

it('refuses the directory to a guest', function () {
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);

    ($this->as)($guest)
        ->getJson("{$this->base}/directory")
        ->assertForbidden();
});

it('starts a conversation, and returns the existing one the second time', function () {
    $token = mobileToken($this->owner);

    $created = $this->withToken($token)
        ->postJson($this->base, ['participant_ids' => [$this->colleague->id]])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)
        ->postJson($this->base, ['participant_ids' => [$this->colleague->id]])
        ->assertOk()
        ->assertJsonPath('data.id', $created);

    expect(Conversation::query()->count())->toBe(1);
});

it('validates who a conversation is with', function () {
    ($this->as)($this->owner)
        ->postJson($this->base, ['participant_ids' => [$this->owner->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('participant_ids');
});

it('opens a conversation with its first page of history', function () {
    $conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    makeConversationMessage($conversation, $this->colleague, 'Hello there');

    ($this->as)($this->owner)
        ->getJson("{$this->base}/{$conversation->public_id}")
        ->assertOk()
        ->assertJsonPath('data.conversation.id', $conversation->public_id)
        ->assertJsonPath('data.history.messages.0.body', 'Hello there')
        ->assertJsonPath('data.abilities.post', true)
        ->assertJsonPath('data.abilities.leave', false)
        ->assertJsonPath('data.huddle', null);
});

it('leaves out the history page when asked to', function () {
    $conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    makeConversationMessage($conversation, $this->colleague, 'Hello there');

    ($this->as)($this->owner)
        ->getJson("{$this->base}/{$conversation->public_id}?history=0")
        ->assertOk()
        ->assertJsonPath('data.conversation.id', $conversation->public_id)
        ->assertJsonPath('data.history', null);
});

it('hides a conversation from someone not in it', function () {
    $conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    $outsider = joinWorkspace($this->workspace);

    ($this->as)($outsider)
        ->getJson("{$this->base}/{$conversation->public_id}")
        ->assertNotFound();
});

it('hides a conversation from another workspace', function () {
    [$otherWorkspace, $otherOwner] = makeWorkspaceWithOwner();
    $conversation = makeConversation($otherWorkspace, [$otherOwner, joinWorkspace($otherWorkspace)]);

    ($this->as)($this->owner)
        ->getJson("{$this->base}/{$conversation->public_id}")
        ->assertNotFound();
});

it('renames a group conversation', function () {
    $group = makeConversation($this->workspace, [$this->owner, $this->colleague, joinWorkspace($this->workspace)], 'Launch');

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$group->public_id}", ['name' => 'Launch crew'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Launch crew');
});

it('will not rename a direct message', function () {
    $direct = makeConversation($this->workspace, [$this->owner, $this->colleague]);

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$direct->public_id}", ['name' => 'Nope'])
        ->assertForbidden();
});

it('mutes a conversation for the viewer only', function () {
    $conversation = makeConversation($this->workspace, [$this->owner, $this->colleague]);

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$conversation->public_id}/preferences", ['is_muted' => true])
        ->assertOk()
        ->assertJsonPath('data.is_muted', true);

    expect($conversation->participants()->where('user_id', $this->colleague->id)->value('is_muted'))->toBeFalse();
});

it('adds people to a group conversation and lets a member leave it', function () {
    $third = joinWorkspace($this->workspace);
    $group = makeConversation($this->workspace, [$this->owner, $this->colleague, joinWorkspace($this->workspace)], 'Launch');

    ($this->as)($this->owner)
        ->postJson("{$this->base}/{$group->public_id}/participants", ['participant_ids' => [$third->id]])
        ->assertOk()
        ->assertJsonPath('data.added.0.id', $third->id)
        ->assertJsonCount(4, 'data.conversation.participants');

    ($this->as)($third)
        ->deleteJson("{$this->base}/{$group->public_id}/participants")
        ->assertNoContent();

    expect($group->participants()->where('user_id', $third->id)->first()->hasLeft())->toBeTrue();
});

it('will not add people to, or leave, a direct message', function () {
    $direct = makeConversation($this->workspace, [$this->owner, $this->colleague]);
    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->postJson("{$this->base}/{$direct->public_id}/participants", ['participant_ids' => [joinWorkspace($this->workspace)->id]])
        ->assertForbidden();

    $this->withToken($token)
        ->deleteJson("{$this->base}/{$direct->public_id}/participants")
        ->assertForbidden();
});

it('needs a mobile token', function () {
    $this->getJson($this->base)->assertUnauthorized();

    ($this->as)(User::factory()->create())
        ->getJson($this->base)
        ->assertNotFound();
});
