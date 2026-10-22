<?php

use App\Enums\ChannelType;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\User;

beforeEach(function () {
    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->shareFile = fn ($channel, $sender, string $name = 'budget.pdf') => MessageAttachment::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'message_id' => makeMessage($channel, $sender)->id,
        'uploaded_by' => $sender->id,
        'original_name' => $name,
        'mime_type' => 'application/pdf',
    ]);
});

it('lists files from channels the viewer can read', function () {
    $file = ($this->shareFile)($this->channel, $this->owner, 'quarterly.pdf');

    $private = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($private, $this->owner);
    ($this->shareFile)($private, $this->owner, 'secret.pdf');

    $member = joinWorkspace($this->workspace);
    addToChannel($this->channel, $member);

    ($this->as)($member)
        ->getJson("/api/v1/w/{$this->workspace->slug}/files")
        ->assertOk()
        ->assertJsonCount(1, 'data.files')
        ->assertJsonPath('data.files.0.id', $file->id)
        ->assertJsonPath('data.files.0.name', 'quarterly.pdf')
        ->assertJsonPath('data.next_cursor', null)
        ->assertJsonPath('data.file_canvas_sidebar.recent.0.id', 'file:'.$file->id);
});

it('pages with the before cursor and filters by name', function () {
    config(['snack.uploads.browser_page_size' => 1]);

    $older = ($this->shareFile)($this->channel, $this->owner, 'older.pdf');
    $newer = ($this->shareFile)($this->channel, $this->owner, 'newer.pdf');

    ($this->as)($this->owner)
        ->getJson("/api/v1/w/{$this->workspace->slug}/files")
        ->assertJsonPath('data.files.0.id', $newer->id)
        ->assertJsonPath('data.next_cursor', $newer->id);

    ($this->as)($this->owner)
        ->getJson("/api/v1/w/{$this->workspace->slug}/files?before={$newer->id}")
        ->assertJsonPath('data.files.0.id', $older->id);

    ($this->as)($this->owner)
        ->getJson("/api/v1/w/{$this->workspace->slug}/files?q=old")
        ->assertJsonPath('data.files.0.id', $older->id)
        ->assertJsonPath('data.filters.q', 'old');
});

it('stars and unstars a file', function () {
    $file = ($this->shareFile)($this->channel, $this->owner);
    $url = "/api/v1/w/{$this->workspace->slug}/files/{$file->id}/star";

    ($this->as)($this->owner)->postJson($url)->assertNoContent();
    ($this->as)($this->owner)->postJson($url)->assertNoContent();

    expect(FileCanvasStar::query()->where('message_attachment_id', $file->id)->count())->toBe(1);

    ($this->as)($this->owner)->deleteJson($url)->assertNoContent();

    expect(FileCanvasStar::query()->count())->toBe(0);
});

it('will not star a file the viewer cannot read', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($private, $this->owner);
    $file = ($this->shareFile)($private, $this->owner);

    ($this->as)(joinWorkspace($this->workspace))
        ->postJson("/api/v1/w/{$this->workspace->slug}/files/{$file->id}/star")
        ->assertNotFound();
});
