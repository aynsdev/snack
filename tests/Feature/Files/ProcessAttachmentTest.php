<?php

use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An unattached upload whose bytes are really on the fake disk.
 */
function uploadedImage(Workspace $workspace, User $uploader): MessageAttachment
{
    $file = UploadedFile::fake()->image('screenshot.png', 1200, 800);

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $uploader->id,
        'disk' => 'local',
        'original_name' => 'screenshot.png',
        'mime_type' => 'image/png',
        'path' => 'workspaces/1/attachments/screenshot.png',
        'metadata' => [],
    ]);

    Storage::disk('local')->put(
        $attachment->path,
        (string) file_get_contents($file->getRealPath()),
    );

    return $attachment;
}

it('writes a thumbnail and records the dimensions', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $attachment = uploadedImage($workspace, $owner);

    (new ProcessAttachment($attachment->id))->handle(app(ImageProcessor::class));

    $attachment->refresh();

    expect($attachment->thumbnail_path)->not->toBeNull()
        ->and($attachment->metadata['width'])->toBe(1200)
        ->and($attachment->metadata['height'])->toBe(800);

    Storage::disk('local')->assertExists($attachment->thumbnail_path);

    // Bounded on the long edge, so a panorama does not become a huge thumbnail.
    $size = getimagesizefromstring(
        Storage::disk('local')->get($attachment->thumbnail_path),
    );

    expect(max($size[0], $size[1]))
        ->toBeLessThanOrEqual(config('snack.uploads.thumbnail_max_edge'));
});

it('leaves a non-image alone', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'mime_type' => 'application/pdf',
        'thumbnail_path' => null,
    ]);

    (new ProcessAttachment($attachment->id))->handle(app(ImageProcessor::class));

    expect($attachment->refresh()->thumbnail_path)->toBeNull();
});

it('does nothing for an attachment deleted before the job ran', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $attachment = uploadedImage($workspace, $owner);
    $id = $attachment->id;

    $attachment->delete();

    // A no-op rather than a failure: an upload can legitimately be discarded
    // between dispatch and execution.
    (new ProcessAttachment($id))->handle(app(ImageProcessor::class));
})->throwsNoExceptions();

it('leaves a file it cannot decode without a thumbnail', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'mime_type' => 'image/png',
        'path' => 'workspaces/1/attachments/broken.png',
        'thumbnail_path' => null,
    ]);

    Storage::disk('local')->put($attachment->path, 'this is not a png');

    (new ProcessAttachment($attachment->id))->handle(app(ImageProcessor::class));

    // Degrades to a file chip rather than failing the job.
    expect($attachment->refresh()->thumbnail_path)->toBeNull();
});
