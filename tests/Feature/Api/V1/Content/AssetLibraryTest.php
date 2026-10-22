<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('snack.uploads.disk', 'local'));

    // Guards cache the resolved user, so a fresh token for someone else
    // needs them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->base = "/api/v1/w/{$this->workspace->slug}/assets";
});

it('lists the whole library at the top and a folder subtree inside one', function () {
    $brand = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brand']);
    $logos = AssetFolder::factory()->inside($brand)->create(['name' => 'Logos']);
    $loose = Asset::factory()->withFile()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
    $nested = Asset::factory()->inside($logos)->withFile()->create(['created_by' => $this->owner->id]);

    ($this->as)($this->owner)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(2, 'data.folders')
        ->assertJsonCount(2, 'data.assets')
        ->assertJsonPath('data.folder', null)
        ->assertJsonPath('data.abilities.create_folder', true);

    ($this->as)($this->owner)
        ->getJson("{$this->base}/folders/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.folder.id', $brand->id)
        ->assertJsonPath('data.folder.folder_count', 1)
        ->assertJsonCount(1, 'data.assets')
        ->assertJsonPath('data.assets.0.id', $nested->id)
        ->assertJsonPath('data.assets.0.file.download_url', fn (string $url) => str_starts_with($url, '/files/'));

    ($this->as)($this->owner)
        ->getJson("{$this->base}/folders/{$logos->id}")
        ->assertJsonPath('data.breadcrumb.0.name', 'Brand');

    expect($loose->id)->not->toBe($nested->id);
});

it('hides the library from guests and folders from other workspaces', function () {
    $guest = joinWorkspace($this->workspace, role: WorkspaceRole::Guest);
    $foreign = AssetFolder::factory()->create();

    ($this->as)($guest)->getJson($this->base)->assertNotFound();
    ($this->as)($this->owner)->getJson("{$this->base}/folders/{$foreign->id}")->assertNotFound();
});

it('adds a file to the library', function () {
    $folder = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id]);

    ($this->as)($this->owner)
        ->post($this->base, [
            'file' => UploadedFile::fake()->create('handbook.pdf', 40, 'application/pdf'),
            'name' => 'Employee handbook',
            'folder_id' => $folder->id,
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Employee handbook')
        ->assertJsonPath('data.folder_id', $folder->id)
        ->assertJsonPath('data.file.name', 'handbook.pdf')
        ->assertJsonPath('data.can_manage', true);

    ($this->as)($this->owner)
        ->postJson($this->base, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('renames and moves an asset, then deletes it with an audit entry', function () {
    $folder = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id]);
    $asset = Asset::factory()->withFile()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$asset->id}", ['name' => 'Logo', 'folder_id' => $folder->id])
        ->assertOk()
        ->assertJsonPath('data.name', 'Logo')
        ->assertJsonPath('data.folder_id', $folder->id);

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/{$asset->id}")
        ->assertNoContent();

    expect(Asset::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AssetDeleted)->count())->toBe(1);
});

it('refuses another member managing an asset they did not add', function () {
    $asset = Asset::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
    $member = joinWorkspace($this->workspace);

    ($this->as)($member)
        ->patchJson("{$this->base}/{$asset->id}", ['name' => 'Mine now'])
        ->assertForbidden();
});

it('creates, renames, moves and deletes a folder', function () {
    $parent = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id]);

    $id = ($this->as)($this->owner)
        ->postJson("{$this->base}/folders", ['name' => 'Contracts', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Contracts')
        ->assertJsonPath('data.parent_id', $parent->id)
        ->json('data.id');

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/folders/{$id}", ['name' => 'Signed contracts', 'parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Signed contracts')
        ->assertJsonPath('data.parent_id', null);

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/folders/{$id}")
        ->assertNoContent();

    expect(AssetFolder::query()->whereKey($id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::AssetFolderDeleted)->count())->toBe(1);
});

it('refuses to let a member delete a folder that still has things in it', function () {
    $member = joinWorkspace($this->workspace);
    $folder = AssetFolder::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $member->id]);
    Asset::factory()->inside($folder)->create(['created_by' => $member->id]);

    ($this->as)($member)
        ->deleteJson("{$this->base}/folders/{$folder->id}")
        ->assertForbidden();
});
