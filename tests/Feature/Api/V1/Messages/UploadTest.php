<?php

use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);

    $this->base = "/api/v1/w/{$this->workspace->slug}";
    $this->withToken(mobileToken($this->owner));
});

it('uploads a file and sends it on a message', function () {
    $attachmentId = $this->post("{$this->base}/uploads", [
        'file' => UploadedFile::fake()->create('budget.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('attachment.name', 'budget.pdf')
        ->json('attachment.id');

    $this->postJson("{$this->base}/channels/{$this->channel->slug}/messages", ['attachment_ids' => [$attachmentId]])
        ->assertCreated();

    expect(MessageAttachment::query()->sole()->message_id)->not->toBeNull();
});

it('discards an unattached upload', function () {
    $attachmentId = $this->post("{$this->base}/uploads", [
        'file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('attachment.id');

    $this->deleteJson("{$this->base}/uploads/{$attachmentId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('refuses to discard somebody else\'s upload', function () {
    $attachmentId = $this->post("{$this->base}/uploads", [
        'file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('attachment.id');

    $member = joinWorkspace($this->workspace);

    // The guard remembers the last request's user; forget it to switch person.
    app('auth')->forgetGuards();

    $this->withToken(mobileToken($member))
        ->deleteJson("{$this->base}/uploads/{$attachmentId}")
        ->assertNotFound();
});
