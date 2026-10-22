<?php

use App\Enums\ChannelType;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\SavedMessage;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);
    $this->message = makeMessage($this->channel, $this->owner, 'Original');

    $this->base = "/api/v1/w/{$this->workspace->slug}";
    $this->withToken(mobileToken($this->owner));
});

it('edits and deletes a message', function () {
    $this->patchJson("{$this->base}/messages/{$this->message->id}", ['body' => 'Edited'])
        ->assertOk()
        ->assertJsonPath('message.body', 'Edited');

    $this->deleteJson("{$this->base}/messages/{$this->message->id}")
        ->assertOk()
        ->assertJsonPath('id', $this->message->id);

    expect(Message::find($this->message->id))->toBeNull();
});

it('refuses editing somebody else\'s message', function () {
    $member = joinWorkspace($this->workspace);
    addToChannel($this->channel, $member);

    $this->withToken(mobileToken($member))
        ->patchJson("{$this->base}/messages/{$this->message->id}", ['body' => 'Hijacked'])
        ->assertForbidden();
});

it('hides a message in a private channel from a non-member', function () {
    $secret = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($secret, $this->owner);
    $hidden = makeMessage($secret, $this->owner);
    $member = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($member))
        ->postJson("{$this->base}/messages/{$hidden->id}/save")
        ->assertNotFound();
});

it('pins and unpins', function () {
    $this->postJson("{$this->base}/messages/{$this->message->id}/pin")
        ->assertOk()
        ->assertJsonPath('pinned.0.id', $this->message->id);

    $this->deleteJson("{$this->base}/messages/{$this->message->id}/pin")
        ->assertOk()
        ->assertJsonCount(0, 'pinned');

    expect(PinnedMessage::query()->count())->toBe(0);
});

it('reacts and takes the reaction back', function () {
    $this->postJson("{$this->base}/messages/{$this->message->id}/reactions", ['emoji' => 'thumbsup'])
        ->assertOk()
        ->assertJsonPath('message_id', $this->message->id)
        ->assertJsonCount(1, 'reactions');

    $this->deleteJson("{$this->base}/messages/{$this->message->id}/reactions", ['emoji' => 'thumbsup'])
        ->assertOk()
        ->assertJsonCount(0, 'reactions');
});

it('saves a message and lists it', function () {
    $this->postJson("{$this->base}/messages/{$this->message->id}/save")
        ->assertOk()
        ->assertJsonPath('is_saved', true);

    $this->getJson("{$this->base}/saved")
        ->assertOk()
        ->assertJsonPath('data.0.message.id', $this->message->id)
        ->assertJsonStructure(['data' => [['id', 'notes', 'saved_at', 'message', 'container']]]);

    $this->deleteJson("{$this->base}/messages/{$this->message->id}/save")
        ->assertOk()
        ->assertJsonPath('is_saved', false);

    expect(SavedMessage::query()->count())->toBe(0);
});

it('lists the viewer\'s mentions', function () {
    $bo = joinWorkspace($this->workspace);
    $bo->forceFill(['username' => 'bo'])->save();
    addToChannel($this->channel, $bo);

    $this->postJson("{$this->base}/channels/{$this->channel->slug}/messages", ['body' => 'Morning @bo'])
        ->assertCreated();

    // The guard remembers the last request's user; forget it to switch person.
    app('auth')->forgetGuards();

    $this->withToken(mobileToken($bo))
        ->getJson("{$this->base}/mentions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message.body', 'Morning @bo')
        ->assertJsonPath('data.0.is_read', false);
});
