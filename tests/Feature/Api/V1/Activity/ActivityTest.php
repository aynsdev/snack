<?php

use App\Actions\Messages\SyncMentions;
use App\Enums\WorkspaceRole;

/**
 * The Activity feed over the API: only the open tab's lists are built.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->member);

    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('shows every list on the All tab', function () {
    $message = makeMessage($this->channel, $this->owner, "@{$this->member->username} look");
    app(SyncMentions::class)->handle($message, $this->owner);

    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/activity")
        ->assertOk()
        ->assertJsonPath('data.tab', 'all')
        ->assertJsonCount(1, 'data.mentions')
        ->assertJsonPath('data.mentions.0.message.body', "@{$this->member->username} look")
        ->assertJsonIsArray('data.notifications')
        ->assertJsonIsArray('data.threads');
});

it('builds only the open tab', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/activity?tab=mentions")
        ->assertOk()
        ->assertJsonPath('data.tab', 'mentions')
        ->assertJsonPath('data.notifications', null)
        ->assertJsonPath('data.threads', null)
        ->assertJsonIsArray('data.mentions');
});

it('falls back to All on an unknown tab', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/activity?tab=nonsense")
        ->assertOk()
        ->assertJsonPath('data.tab', 'all');
});

it('keeps a non-member out', function () {
    [, $stranger] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($stranger))
        ->getJson("{$this->base}/activity")
        ->assertNotFound();
});
