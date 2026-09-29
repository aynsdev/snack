<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\BrowseAssetsTool;
use App\Mcp\Tools\ReadFileTool;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * The asset library and file contents over MCP: browsable by any member but a
 * guest, readable only where the person could open the file themselves.
 */
beforeEach(function () {
    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->brand = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brand']);
    AssetFolder::factory()->inside($this->brand)->create(['name' => 'Logos']);
    Asset::factory()->inside($this->brand)->withFile('text/markdown', 'voice.md')
        ->create(['name' => 'Tone of voice', 'created_by' => $this->owner->id]);
});

/**
 * Put bytes behind an attachment row on the faked disk.
 */
function storeBytes(MessageAttachment $file, string $bytes): MessageAttachment
{
    Storage::disk($file->disk)->put($file->path, $bytes);
    $file->forceFill(['size' => strlen($bytes)])->save();

    return $file;
}

it('lists the top level of the library', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(BrowseAssetsTool::class)
        ->assertOk()
        ->assertSee(['Brand', '1 folder(s), 1 file(s)']);
});

it('opens a folder by name, with file ids', function () {
    [$user] = connectAs($this->member, $this->workspace);
    $file = MessageAttachment::query()->whereNotNull('asset_id')->sole();

    SnackServer::actingAs($user)
        ->tool(BrowseAssetsTool::class, ['folder' => 'brand'])
        ->assertOk()
        ->assertSee(['Asset library / Brand', 'Logos', "file #{$file->id} Tone of voice"]);
});

it('keeps guests out of the library', function () {
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    [$user] = connectAs($guest, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(BrowseAssetsTool::class)
        ->assertHasErrors()
        ->assertDontSee('Brand');
});

it('reads a text asset', function () {
    $file = storeBytes(MessageAttachment::query()->whereNotNull('asset_id')->sole(), "# Voice\nWarm, plain, brief.");
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ReadFileTool::class, ['file_id' => $file->id])
        ->assertOk()
        ->assertSee('Warm, plain, brief.');
});

it('returns an image as an image', function () {
    $asset = Asset::factory()->withFile('image/png', 'logo.png')->create(['workspace_id' => $this->workspace->id]);
    $file = storeBytes($asset->file, 'fake-png-bytes');
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ReadFileTool::class, ['file_id' => $file->id])
        ->assertOk()
        ->assertSee('logo.png');
});

it('will not read a file from a private channel the person is not in', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);
    $message = makeMessage($private, $this->owner, 'the numbers');
    $file = storeBytes(MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $message->id,
        'mime_type' => 'text/plain',
        'original_name' => 'salaries.txt',
    ]), 'Everyone is underpaid.');
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ReadFileTool::class, ['file_id' => $file->id])
        ->assertHasErrors()
        ->assertDontSee('underpaid');
});

it('reads a file attached to a task the person can see', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Engineering', 'key' => 'ENG']);
    $task = makeTask($project, $this->owner);
    $file = storeBytes(MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'mime_type' => 'text/plain',
        'original_name' => 'steps.txt',
    ]), 'Click login twice.');
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ReadFileTool::class, ['file_id' => $file->id])
        ->assertOk()
        ->assertSee('Click login twice.');
});
