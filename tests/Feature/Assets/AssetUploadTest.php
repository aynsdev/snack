<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\AuditLog;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(config('snack.uploads.disk', 'local'));
});

it('adds a file to the library in one request', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)
        ->post(route('assets.store', $workspace), [
            'file' => UploadedFile::fake()->image('Logo_FINAL_v3.png'),
            'description' => 'Use this on light backgrounds.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $asset = Asset::where('workspace_id', $workspace->id)->sole();

    // The extension is dropped from the library's own name for it, and kept on
    // the file — see StoreAsset::name().
    expect($asset->name)->toBe('Logo_FINAL_v3')
        ->and($asset->description)->toBe('Use this on light backgrounds.')
        ->and($asset->folder_id)->toBeNull()
        ->and($asset->created_by)->toBe($owner->id);

    $file = $asset->file;

    expect($file)->not->toBeNull()
        ->and($file->original_name)->toBe('Logo_FINAL_v3.png')
        ->and($file->asset_id)->toBe($asset->id)
        ->and($file->message_id)->toBeNull()
        ->and($file->task_id)->toBeNull();

    Storage::disk($file->disk)->assertExists($file->path);
});

it('files an upload onto the named shelf', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($owner)
        ->post(route('assets.store', $workspace), [
            'file' => UploadedFile::fake()->create('handbook.pdf', 40, 'application/pdf'),
            'name' => 'Employee handbook',
            'folder_id' => $folder->id,
        ])
        ->assertRedirect();

    expect(Asset::sole()->folder_id)->toBe($folder->id)
        ->and(Asset::sole()->name)->toBe('Employee handbook');
});

it('refuses a shelf from another workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $elsewhere = AssetFolder::factory()->create();

    actingAs($owner)
        ->post(route('assets.store', $workspace), [
            'file' => UploadedFile::fake()->image('logo.png'),
            'folder_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('folder_id');

    expect(Asset::count())->toBe(0);
});

it('refuses an executable', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)
        ->post(route('assets.store', $workspace), [
            'file' => UploadedFile::fake()->create('payload.exe', 4),
        ])
        ->assertSessionHasErrors('file');

    expect(Asset::count())->toBe(0);
});

it('keeps guests out of the library', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    actingAs($guest)
        ->post(route('assets.store', $workspace), [
            'file' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertNotFound();
});

/**
 * The bytes behind an asset are an ordinary attachment, which means three
 * existing code paths would happily take them away if ownership were not
 * recorded on the attachment row itself. Each has its own test below.
 */
it('does not let the composer’s tray delete an asset’s file', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $file = Asset::sole()->file;

    actingAs($owner)
        ->deleteJson(route('uploads.destroy', [$workspace, $file]))
        ->assertNotFound();

    expect(MessageAttachment::find($file->id))->not->toBeNull();
    Storage::disk($file->disk)->assertExists($file->path);
});

it('does not sweep an asset’s file as an abandoned upload', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $file = Asset::sole()->file;

    // Well past the orphan window, which is what the sweep reads.
    $file->forceFill(['created_at' => now()->subDays(30)])->save();

    $this->artisan('snack:prune-uploads')->assertSuccessful();

    expect(MessageAttachment::find($file->id))->not->toBeNull();
});

it('does not let a message claim an asset’s file', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $file = Asset::sole()->file;

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Here you go',
            'attachment_ids' => [$file->id],
        ])
        ->assertNotFound();

    expect($file->refresh()->message_id)->toBeNull()
        ->and($file->asset_id)->not->toBeNull();
});

it('serves an asset’s file to any member over the signed route', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $asset = Asset::sole();

    $url = (new AssetResource($asset->load('file')))
        ->resolve(request())['file']['download_url'];

    actingAs($member)->get($url)->assertOk();
});

it('refuses an asset’s file to a guest even with a valid signature', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $asset = Asset::sole();

    $url = (new AssetResource($asset->load('file')))
        ->resolve(request())['file']['download_url'];

    // The signature is fine; the authorization is re-checked on every hit.
    actingAs($guest)->get($url)->assertNotFound();
});

it('renames an asset without moving it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $asset = Asset::factory()->inside($folder)->create(['created_by' => $owner->id]);

    actingAs($owner)
        ->patch(route('assets.update', [$workspace, $asset]), ['name' => 'Primary logo'])
        ->assertRedirect();

    $asset->refresh();

    expect($asset->name)->toBe('Primary logo')
        ->and($asset->folder_id)->toBe($folder->id);
});

it('moves an asset to another shelf', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $from = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $to = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $asset = Asset::factory()->inside($from)->create(['created_by' => $owner->id]);

    actingAs($owner)
        ->patch(route('assets.update', [$workspace, $asset]), ['folder_id' => $to->id])
        ->assertRedirect();

    expect($asset->refresh()->folder_id)->toBe($to->id);
});

it('refuses to let another member re-label somebody else’s asset', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $curator = joinWorkspace($workspace);
    $other = joinWorkspace($workspace);

    $asset = Asset::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $curator->id,
    ]);

    actingAs($other)
        ->patch(route('assets.update', [$workspace, $asset]), ['name' => 'Mine'])
        ->assertForbidden();
});

it('deletes an asset, its attachment row, and the bytes', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ]);

    $asset = Asset::sole();
    $file = $asset->file;

    actingAs($owner)
        ->delete(route('assets.destroy', [$workspace, $asset]))
        ->assertRedirect();

    expect(Asset::find($asset->id))->toBeNull()
        ->and(MessageAttachment::find($file->id))->toBeNull();

    Storage::disk($file->disk)->assertMissing($file->path);
});

/**
 * The cascade would take the rows on its own. It cannot reach the disk, which
 * is what this is really asserting.
 */
it('deletes the bytes under a deleted folder', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->image('logo.png'),
        'folder_id' => $folder->id,
    ]);

    $file = Asset::sole()->file;

    actingAs($owner)->delete(route('assets.folders.destroy', [$workspace, $folder]));

    expect(MessageAttachment::find($file->id))->toBeNull();
    Storage::disk($file->disk)->assertMissing($file->path);
});

it('audits an asset deletion with both of its names', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)->post(route('assets.store', $workspace), [
        'file' => UploadedFile::fake()->create('msa-signed.pdf', 20, 'application/pdf'),
        'name' => 'Master services agreement',
    ]);

    actingAs($owner)->delete(route('assets.destroy', [$workspace, Asset::sole()]));

    $entry = AuditLog::where('action', AuditAction::AssetDeleted)->sole();

    expect($entry->old_values['name'])->toBe('Master services agreement')
        ->and($entry->old_values['file_name'])->toBe('msa-signed.pdf');
});
