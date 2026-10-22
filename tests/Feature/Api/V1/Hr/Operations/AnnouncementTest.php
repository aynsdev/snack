<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Announcement;
use App\Models\Message;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->channel = makeChannel($this->workspace, ChannelType::Announcement, ['name' => 'announcements']);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->member);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/announcements";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
});

it('lists announcements pinned first, with the channels an admin may post to', function () {
    $this->workspace->announcements()->create(['author_id' => $this->owner->id, 'title' => 'Old news', 'body' => 'Hello']);
    $this->workspace->announcements()->create(['author_id' => $this->owner->id, 'title' => 'Office move', 'body' => 'Monday', 'is_pinned' => true]);

    ($this->as)($this->owner)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Office move')
        ->assertJsonPath('meta.can_manage', true)
        ->assertJsonPath('meta.channels.0.id', $this->channel->id)
        ->assertJsonPath('meta.current_page', 1);

    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonPath('meta.channels', []);
});

it('publishes to a channel, revises, and withdraws an announcement', function () {
    $id = ($this->as)($this->owner)
        ->postJson($this->base, ['title' => 'Office move', 'body' => 'We move Monday.', 'channel_id' => $this->channel->id])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Office move')
        ->assertJsonPath('data.channel.slug', $this->channel->slug)
        ->assertJsonPath('data.is_posted', true)
        ->json('data.id');

    $announcement = Announcement::query()->findOrFail($id);

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$id}", ['title' => 'Office move', 'body' => 'We move Tuesday.'])
        ->assertOk()
        ->assertJsonPath('data.body', 'We move Tuesday.');

    expect(Message::query()->findOrFail($announcement->message_id)->body)->toContain('Tuesday');

    ($this->as)($this->owner)->deleteJson("{$this->base}/{$id}")->assertNoContent();

    expect(Announcement::query()->find($id))->toBeNull();
});

it('keeps members from posting or deleting, and outsiders out', function () {
    $announcement = $this->workspace->announcements()->create(['author_id' => $this->owner->id, 'title' => 'Hi', 'body' => 'There']);

    ($this->as)($this->member)->postJson($this->base, ['title' => 'x', 'body' => 'y'])->assertForbidden();
    ($this->as)($this->member)->deleteJson("{$this->base}/{$announcement->id}")->assertForbidden();
    ($this->as)(User::factory()->create())->getJson($this->base)->assertNotFound();
});

it('validates the announcement', function () {
    ($this->as)($this->owner)->postJson($this->base, ['body' => 'y'])->assertUnprocessable()->assertJsonValidationErrors('title');
});
