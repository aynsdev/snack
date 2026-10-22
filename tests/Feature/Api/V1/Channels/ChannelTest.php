<?php

use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Channel;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'design']);
    addToChannel($this->channel, $this->owner, ChannelRole::Admin);

    $this->url = fn (string $suffix = ''): string => "/api/v1/w/{$this->workspace->slug}/channels".$suffix;
    $this->withToken(mobileToken($this->owner));
});

it('lists joined and joinable channels', function () {
    makeChannel($this->workspace, attributes: ['name' => 'random']);

    $this->getJson(($this->url)())
        ->assertOk()
        ->assertJsonPath('data.joined.0.slug', $this->channel->slug)
        ->assertJsonCount(1, 'data.joinable');
});

it('creates a channel', function () {
    $this->postJson(($this->url)(), ['name' => 'launch plans', 'type' => 'public'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'launch plans')
        ->assertJsonPath('data.is_member', true);
});

it('refuses an announcement channel from a plain member', function () {
    $member = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($member))
        ->postJson(($this->url)(), ['name' => 'news', 'type' => 'announcement'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('shows a channel with its abilities, pins and first page of history', function () {
    makeMessage($this->channel, $this->owner, 'Hello');

    $this->getJson(($this->url)("/{$this->channel->slug}"))
        ->assertOk()
        ->assertJsonPath('data.channel.slug', $this->channel->slug)
        ->assertJsonPath('data.channel.member_count', 1)
        ->assertJsonPath('data.abilities.can_post', true)
        ->assertJsonPath('data.abilities.can_manage', true)
        ->assertJsonPath('data.history.messages.0.body', 'Hello')
        ->assertJsonStructure(['data' => ['canvases', 'pinned', 'huddle', 'abilities' => ['can_pin', 'can_huddle', 'can_create_canvas']]]);
});

it('hides a private channel from a non-member', function () {
    $secret = makeChannel($this->workspace, ChannelType::Private);
    $member = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($member))
        ->getJson(($this->url)("/{$secret->slug}"))
        ->assertNotFound();
});

it('renames a channel and answers with its new slug', function () {
    $this->patchJson(($this->url)("/{$this->channel->slug}"), ['name' => 'brand'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'brand');
});

it('refuses an edit from a plain member', function () {
    $member = joinWorkspace($this->workspace);
    addToChannel($this->channel, $member);

    $this->withToken(mobileToken($member))
        ->patchJson(($this->url)("/{$this->channel->slug}"), ['name' => 'mine'])
        ->assertForbidden();
});

it('lists the roster', function () {
    addToChannel($this->channel, joinWorkspace($this->workspace));

    $this->getJson(($this->url)("/{$this->channel->slug}/members"))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'role', 'joined_at', 'user' => ['id', 'name']]]]);
});

it('archives and restores a channel', function () {
    $this->postJson(($this->url)("/{$this->channel->slug}/archive"))
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $this->deleteJson(($this->url)("/{$this->channel->slug}/archive"))
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);
});

it('refuses archiving from a plain member', function () {
    $member = joinWorkspace($this->workspace);
    addToChannel($this->channel, $member);

    $this->withToken(mobileToken($member))
        ->postJson(($this->url)("/{$this->channel->slug}/archive"))
        ->assertForbidden();
});

it('joins and leaves a channel', function () {
    $member = joinWorkspace($this->workspace);
    $this->withToken(mobileToken($member));

    $this->postJson(($this->url)("/{$this->channel->slug}/membership"))
        ->assertOk()
        ->assertJsonPath('data.is_member', true);

    expect($this->channel->hasMember($member))->toBeTrue();

    $this->deleteJson(($this->url)("/{$this->channel->slug}/membership"))->assertNoContent();

    expect($this->channel->fresh()->hasMember($member))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(2);
});

it('adds and removes other members', function () {
    $ada = joinWorkspace($this->workspace);

    $this->postJson(($this->url)("/{$this->channel->slug}/members"), ['user_ids' => [$ada->id]])
        ->assertCreated()
        ->assertJsonPath('data.0.id', $ada->id);

    $this->postJson(($this->url)("/{$this->channel->slug}/members"), ['user_ids' => [$ada->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_ids');

    $membership = $this->channel->membershipFor($ada);

    $this->deleteJson(($this->url)("/{$this->channel->slug}/members/{$membership->id}"))->assertNoContent();

    expect($this->channel->fresh()->hasMember($ada))->toBeFalse();
});

it('refuses adding members to a plain member', function () {
    $member = joinWorkspace($this->workspace, role: WorkspaceRole::Member);
    addToChannel($this->channel, $member);
    $ada = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($member))
        ->postJson(($this->url)("/{$this->channel->slug}/members"), ['user_ids' => [$ada->id]])
        ->assertForbidden();
});

it('mutes a channel for the viewer only', function () {
    $this->patchJson(($this->url)("/{$this->channel->slug}/preferences"), ['is_muted' => true, 'notification_level' => 'mentions'])
        ->assertOk()
        ->assertJsonPath('data.is_muted', true)
        ->assertJsonPath('data.notification_level', 'mentions');
});

it('refuses preferences on a channel the viewer has not joined', function () {
    $other = makeChannel($this->workspace);

    $this->patchJson(($this->url)("/{$other->slug}/preferences"), ['is_muted' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel');
});

it('posts and pages through messages', function () {
    $this->postJson(($this->url)("/{$this->channel->slug}/messages"), ['body' => 'From my phone'])
        ->assertCreated()
        ->assertJsonPath('message.body', 'From my phone');

    $this->getJson(($this->url)("/{$this->channel->slug}/messages"))
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'From my phone')
        ->assertJsonPath('has_more', false);
});

it('moves the read pointer', function () {
    $message = makeMessage($this->channel, $this->owner);

    $this->postJson(($this->url)("/{$this->channel->slug}/read"), ['last_read_message_id' => $message->id])
        ->assertOk()
        ->assertJsonPath('last_read_message_id', $message->id);
});

it('offers mentionable people', function () {
    $ada = joinWorkspace($this->workspace);
    addToChannel($this->channel, $ada);

    $this->getJson(($this->url)("/{$this->channel->slug}/mentionable"))
        ->assertOk()
        ->assertJsonPath('people.0.id', $ada->id);
});

it('starts and leaves a huddle', function () {
    $this->postJson(($this->url)("/{$this->channel->slug}/huddle"))
        ->assertOk()
        ->assertJsonPath('huddle.is_live', true)
        ->assertJsonStructure(['connection' => ['url', 'token', 'identity']]);

    $this->deleteJson(($this->url)("/{$this->channel->slug}/huddle"))
        ->assertOk()
        ->assertJsonPath('huddle', null);
});

it('does not reach another workspace\'s channel', function () {
    [$elsewhere] = makeWorkspaceWithOwner();
    $foreign = makeChannel($elsewhere);

    $this->getJson(($this->url)("/{$foreign->slug}"))->assertNotFound();
    expect(Channel::query()->count())->toBe(2);
});
