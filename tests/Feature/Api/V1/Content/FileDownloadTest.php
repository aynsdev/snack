<?php

use App\Enums\ChannelType;
use App\Http\Resources\AttachmentResource;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($this->channel, $this->owner);

    $this->attachment = MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => makeMessage($this->channel, $this->owner)->id,
        'uploaded_by' => $this->owner->id,
        'disk' => 'local',
        'original_name' => 'budget.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($this->attachment->path, 'file contents');
});

it('downloads an attachment with a bearer token and no signature', function () {
    $response = ($this->as)($this->owner)
        ->get("/api/v1/files/{$this->attachment->id}")
        ->assertOk();

    expect($response->streamedContent())->toBe('file contents')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('serves a resource url once the client prefixes it with /api/v1', function () {
    $previewUrl = AttachmentResource::payload($this->attachment)['preview_url'];

    $response = ($this->as)($this->owner)
        ->get('/api/v1'.$previewUrl)
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('refuses the web download url to a bearer token', function () {
    ($this->as)($this->owner)
        ->getJson(AttachmentResource::payload($this->attachment)['download_url'])
        ->assertUnauthorized();
});

it('404s an attachment for someone outside its private channel', function () {
    $outsider = joinWorkspace($this->workspace);

    ($this->as)($outsider)
        ->get("/api/v1/files/{$this->attachment->id}")
        ->assertNotFound();
});

it('404s a thumbnail that was never generated', function () {
    ($this->as)($this->owner)
        ->get("/api/v1/files/{$this->attachment->id}/thumbnail")
        ->assertNotFound();
});

it('404s play for a file that is not a video', function () {
    ($this->as)($this->owner)
        ->get("/api/v1/files/{$this->attachment->id}/play")
        ->assertNotFound();
});

it('requires a token', function () {
    $this->getJson("/api/v1/files/{$this->attachment->id}")->assertUnauthorized();
});
