<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Canvas;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists the canvases the viewer can see without their documents', function () {
    $canvas = Canvas::factory()->forWorkspace($this->workspace, $this->owner)->create(['title' => 'Launch plan']);
    $private = makeChannel($this->workspace, ChannelType::Private);
    Canvas::factory()->forWorkspace($this->workspace, joinWorkspace($this->workspace))->create([
        'title' => 'Private notes',
        'channel_id' => $private->id,
    ]);
    Canvas::factory()->create(['title' => 'Another workspace']);

    ($this->as)($this->owner)
        ->getJson("{$this->base}/canvases?sort=title")
        ->assertOk()
        ->assertJsonCount(1, 'data.canvases')
        ->assertJsonPath('data.canvases.0.id', $canvas->public_id)
        ->assertJsonMissingPath('data.canvases.0.content')
        ->assertJsonPath('data.filters.sort', 'title')
        ->assertJsonPath('data.pagination.has_more', false)
        ->assertJsonPath('data.abilities.create', true);
});

it('creates a canvas from a template', function () {
    ($this->as)($this->owner)
        ->postJson("{$this->base}/canvases", ['template' => 'meeting-notes'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Meeting notes')
        ->assertJsonPath('data.content.type', 'doc')
        ->assertJsonPath('data.can_update', true);

    ($this->as)($this->owner)
        ->postJson("{$this->base}/canvases", ['template' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');
});

it('creates canvases in a channel and a conversation', function () {
    $channel = makeChannel($this->workspace);
    addToChannel($channel, $this->owner);
    $conversation = makeConversation($this->workspace, [$this->owner, joinWorkspace($this->workspace)]);

    ($this->as)($this->owner)
        ->postJson("{$this->base}/channels/{$channel->slug}/canvases")
        ->assertCreated()
        ->assertJsonPath('data.container.type', 'channel')
        ->assertJsonPath('data.container.key', $channel->slug);

    ($this->as)($this->owner)
        ->postJson("{$this->base}/dms/{$conversation->public_id}/canvases")
        ->assertCreated()
        ->assertJsonPath('data.container.type', 'conversation')
        ->assertJsonPath('data.container.key', $conversation->public_id);

    expect(Canvas::query()->whereNotNull('channel_id')->count())->toBe(1)
        ->and(Canvas::query()->whereNotNull('conversation_id')->count())->toBe(1);
});

it('opens a canvas with its document', function () {
    $canvas = Canvas::factory()->forWorkspace($this->workspace, $this->owner)->create([
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    ($this->as)($this->owner)
        ->getJson("{$this->base}/canvases/{$canvas->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $canvas->public_id)
        ->assertJsonPath('data.content.type', 'doc')
        ->assertJsonPath('data.cover_url', null)
        ->assertJsonPath('data.is_starred', false);
});

it('saves the whole document and rejects unknown nodes', function () {
    $canvas = Canvas::factory()->forWorkspace($this->workspace, $this->owner)->create();
    $member = joinWorkspace($this->workspace);
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Ship it', 'marks' => [['type' => 'bold']]]],
        ]],
    ];

    ($this->as)($member)
        ->patchJson("{$this->base}/canvases/{$canvas->public_id}", ['title' => '  Launch   plan ', 'content' => $content])
        ->assertOk()
        ->assertJsonPath('data.title', 'Launch plan')
        ->assertJsonPath('data.body', 'Ship it')
        ->assertJsonPath('data.editor.id', $member->id);

    ($this->as)($member)
        ->patchJson("{$this->base}/canvases/{$canvas->public_id}", [
            'title' => 'Bad',
            'content' => ['type' => 'doc', 'content' => [['type' => 'script']]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

it('only lets the creator or an admin delete a canvas', function () {
    $creator = joinWorkspace($this->workspace);
    $canvas = Canvas::factory()->forWorkspace($this->workspace, $creator)->create();

    ($this->as)(joinWorkspace($this->workspace))
        ->deleteJson("{$this->base}/canvases/{$canvas->public_id}")
        ->assertForbidden();

    ($this->as)($creator)
        ->deleteJson("{$this->base}/canvases/{$canvas->public_id}")
        ->assertNoContent();

    expect(Canvas::query()->count())->toBe(0);
});

it('stars and unstars a canvas', function () {
    $canvas = Canvas::factory()->forWorkspace($this->workspace, $this->owner)->create();
    $url = "{$this->base}/canvases/{$canvas->public_id}/star";

    ($this->as)($this->owner)->postJson($url)->assertNoContent();

    ($this->as)($this->owner)
        ->getJson("{$this->base}/canvases/{$canvas->public_id}")
        ->assertJsonPath('data.is_starred', true);

    ($this->as)($this->owner)->deleteJson($url)->assertNoContent();

    expect(FileCanvasStar::query()->count())->toBe(0);
});

it('sets and removes a cover', function () {
    Storage::fake(config('snack.uploads.disk', 'local'));
    Queue::fake();

    $canvas = Canvas::factory()->forWorkspace($this->workspace, $this->owner)->create();

    ($this->as)($this->owner)
        ->post("{$this->base}/canvases/{$canvas->public_id}/cover", [
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 600),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.cover_url', fn (string $url) => str_contains($url, 'inline=1'));

    expect(MessageAttachment::query()->sole()->canvas_id)->toBe($canvas->id);

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/canvases/{$canvas->public_id}/cover")
        ->assertNoContent();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('hides canvases from guests and other workspaces', function () {
    $guest = joinWorkspace($this->workspace, role: WorkspaceRole::Guest);
    [$elsewhere, $otherOwner] = makeWorkspaceWithOwner();
    $foreign = Canvas::factory()->forWorkspace($elsewhere, $otherOwner)->create();

    ($this->as)($guest)
        ->getJson("{$this->base}/canvases")
        ->assertNotFound();

    ($this->as)($this->owner)
        ->getJson("{$this->base}/canvases/{$foreign->public_id}")
        ->assertNotFound();
});
