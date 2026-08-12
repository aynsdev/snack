<?php

use App\Http\Resources\AttachmentResource;
use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

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

it('records that a gif moves, and says so to the client', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'reaction.gif',
        'mime_type' => 'image/gif',
        'path' => 'workspaces/1/attachments/reaction.gif',
        'metadata' => [],
    ]);

    Storage::disk('local')->put($attachment->path, animatedGifBytes(3));

    (new ProcessAttachment($attachment->id))->handle(app(ImageProcessor::class));

    $attachment->refresh();

    /*
     * The thumbnail is still written — it is a useful poster in the Files grid
     * — but it is a single frozen frame, so the flag is what tells the timeline
     * to serve the original instead and let it play.
     */
    expect($attachment->metadata['animated'])->toBeTrue()
        ->and($attachment->thumbnail_path)->not->toBeNull()
        ->and(AttachmentResource::payload($attachment)['is_animated'])->toBeTrue();
});

it('marks an ordinary image as not animated', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $attachment = uploadedImage($workspace, $owner);

    (new ProcessAttachment($attachment->id))->handle(app(ImageProcessor::class));

    expect($attachment->refresh()->metadata['animated'])->toBeFalse()
        ->and(AttachmentResource::payload($attachment)['is_animated'])->toBeFalse();
});

/**
 * The operational half of the thumbnail story.
 *
 * A worker that restarted mid-decode, a bucket that was briefly unreachable, or
 * a format the build could not decode until it was upgraded all leave a row that
 * looks exactly like a file which legitimately has no preview — and nothing will
 * ever dispatch that job again on its own. `--missing` is what re-dispatches it,
 * and it is the first thing to reach for when somebody reports that *some* files
 * have previews and others do not.
 */
it('re-queues only the images that ended up with no thumbnail', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    Queue::fake();

    $thumbnailed = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'mime_type' => 'image/png',
        'thumbnail_path' => 'workspaces/1/attachments/done_thumb.jpg',
        'metadata' => ['animated' => false],
    ]);

    $bare = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'mime_type' => 'image/svg+xml',
        'thumbnail_path' => null,
        // Already examined, so the default pass would skip it — this row is
        // reachable only through --missing.
        'metadata' => ['animated' => false],
    ]);

    // Not an image at all: no pass should ever touch it.
    MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'mime_type' => 'application/pdf',
        'thumbnail_path' => null,
    ]);

    artisan('snack:reprocess-attachments', ['--missing' => true])->assertSuccessful();

    Queue::assertPushed(ProcessAttachment::class, 1);
    Queue::assertPushed(
        ProcessAttachment::class,
        fn (ProcessAttachment $job) => (new ReflectionClass($job))
            ->getProperty('attachmentId')
            ->getValue($job) === $bare->id,
    );

    expect($thumbnailed->refresh()->thumbnail_path)->not->toBeNull();
});

/**
 * A GIF89a with several frame blocks, built by hand — `UploadedFile::fake()`
 * only ever produces stills.
 */
function animatedGifBytes(int $frames): string
{
    $out = 'GIF89a'.pack('vv', 4, 6)."\xF7\x00\x00";

    for ($i = 0; $i < 256; $i++) {
        $out .= pack('CCC', $i, 0, 0);
    }

    for ($i = 0; $i < $frames; $i++) {
        $out .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
        $out .= "\x2C".pack('vvvv', 0, 0, 4, 6)."\x00";
        $out .= "\x08\x03\x01".chr($i + 1)."\x00";
    }

    return $out."\x3B";
}
