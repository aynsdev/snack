<?php

use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('parks an upload without attaching it to any message', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $response = actingAs($member)->post(
        route('uploads.store', $workspace),
        ['file' => UploadedFile::fake()->create('budget.pdf', 120, 'application/pdf')],
    );

    $response->assertCreated();

    $attachment = MessageAttachment::query()->sole();

    expect($attachment->message_id)->toBeNull()
        ->and($attachment->workspace_id)->toBe($workspace->id)
        ->and($attachment->uploaded_by)->toBe($member->id)
        ->and($attachment->original_name)->toBe('budget.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('never builds the stored path from the uploaded name', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(
        route('uploads.store', $workspace),
        ['file' => UploadedFile::fake()->create('../../../.env', 4, 'text/plain')],
    );

    $attachment = MessageAttachment::query()->sole();

    // The name survives as data; the key is a server-generated ULID under the
    // workspace's own directory, so traversal has nothing to act on.
    expect($attachment->path)->toStartWith("workspaces/{$workspace->id}/attachments/")
        ->and($attachment->path)->not->toContain('..')
        ->and($attachment->original_name)->not->toContain('/');
});

it('hands the client signed urls and never a path', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $response = actingAs($owner)->post(
        route('uploads.store', $workspace),
        ['file' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain')],
    );

    $payload = $response->json('attachment');
    $attachment = MessageAttachment::query()->sole();

    expect($payload)->not->toHaveKey('path')
        ->and($payload)->not->toHaveKey('disk')
        ->and($payload['download_url'])->toContain('signature=')
        ->and($payload['download_url'])->not->toContain($attachment->path);
});

it('queues the thumbnail and dimension work rather than doing it in the request', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(
        route('uploads.store', $workspace),
        ['file' => UploadedFile::fake()->image('screenshot.png', 400, 300)],
    );

    Queue::assertPushed(ProcessAttachment::class);
});

it('refuses files a browser or an operating system would execute', function (string $name, string $mime) {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)
        ->post(route('uploads.store', $workspace), [
            'file' => UploadedFile::fake()->create($name, 8, $mime),
        ])
        ->assertSessionHasErrors('file');

    expect(MessageAttachment::query()->count())->toBe(0);
})->with([
    'executable' => ['payload.exe', 'application/octet-stream'],
    'shell script' => ['setup.sh', 'text/plain'],
    'php' => ['shell.php', 'text/plain'],
]);

it('refuses a file above the size ceiling', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    config()->set('snack.uploads.max_size_kb', 10);

    actingAs($owner)
        ->post(route('uploads.store', $workspace), [
            'file' => UploadedFile::fake()->create('huge.pdf', 5_000, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');
});

it('rejects an upload from a non-member with a 404', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace(makeWorkspace());

    actingAs($outsider)
        ->post(route('uploads.store', $workspace), [
            'file' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
        ])
        ->assertNotFound();
});

it('lets the uploader discard an upload, deleting the bytes with the row', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(route('uploads.store', $workspace), [
        'file' => UploadedFile::fake()->create('draft.pdf', 4, 'application/pdf'),
    ]);

    $attachment = MessageAttachment::query()->sole();

    actingAs($owner)
        ->delete(route('uploads.destroy', [$workspace, $attachment]))
        ->assertOk();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($attachment->path);
});

it('will not let one member discard another member\'s upload', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    actingAs($owner)->post(route('uploads.store', $workspace), [
        'file' => UploadedFile::fake()->create('draft.pdf', 4, 'application/pdf'),
    ]);

    $attachment = MessageAttachment::query()->sole();

    actingAs($other)
        ->delete(route('uploads.destroy', [$workspace, $attachment]))
        ->assertNotFound();

    expect(MessageAttachment::query()->count())->toBe(1);
});

it('will not discard an upload that is already on a sent message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
    ]);

    actingAs($owner)
        ->delete(route('uploads.destroy', [$workspace, $attachment]))
        ->assertNotFound();
});

it('will not reach an upload belonging to another workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $elsewhere = makeWorkspace();

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $elsewhere->id,
        'uploaded_by' => $owner->id,
    ]);

    // Scope-bound through the workspace, so the id does not resolve at all.
    actingAs($owner)
        ->delete(route('uploads.destroy', [$workspace, $attachment]))
        ->assertNotFound();
});
