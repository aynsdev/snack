<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\AuditLog;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('creates a folder at the top of the library', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    actingAs($owner)
        ->post(route('assets.folders.store', $workspace), ['name' => 'Brand'])
        ->assertRedirect();

    $folder = AssetFolder::where('workspace_id', $workspace->id)->sole();

    expect($folder->name)->toBe('Brand')
        ->and($folder->parent_id)->toBeNull()
        ->and($folder->created_by)->toBe($owner->id);
});

it('creates a folder inside another', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $parent = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($owner)
        ->post(route('assets.folders.store', $workspace), [
            'name' => 'Logos',
            'parent_id' => $parent->id,
        ])
        ->assertRedirect(route('assets.folders.show', [
            'workspace' => $workspace->slug,
            'assetFolder' => AssetFolder::where('name', 'Logos')->sole()->id,
        ]));

    expect(AssetFolder::where('name', 'Logos')->sole()->parent_id)->toBe($parent->id);
});

/**
 * The database enforces this with a partial unique index on `lower(name)`; the
 * request rule exists so the answer arrives as a message on the field rather
 * than as a 500 from a constraint violation.
 */
it('refuses a sibling name that differs only in case', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand']);

    actingAs($owner)
        ->post(route('assets.folders.store', $workspace), ['name' => 'brand'])
        ->assertSessionHasErrors('name');

    expect(AssetFolder::where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('allows the same folder name on two different shelves', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $brand = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand']);
    $legal = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Legal']);

    foreach ([$brand, $legal] as $parent) {
        actingAs($owner)
            ->post(route('assets.folders.store', $workspace), [
                'name' => 'Archive',
                'parent_id' => $parent->id,
            ])
            ->assertSessionHasNoErrors();
    }

    expect(AssetFolder::where('name', 'Archive')->count())->toBe(2);
});

it('refuses a parent folder from another workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $elsewhere = AssetFolder::factory()->create();

    actingAs($owner)
        ->post(route('assets.folders.store', $workspace), [
            'name' => 'Smuggled',
            'parent_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('stops nesting at the depth bound', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $folder = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    for ($depth = 2; $depth <= AssetFolder::MAX_DEPTH; $depth++) {
        $folder = AssetFolder::factory()->inside($folder)->create();
    }

    expect($folder->depth())->toBe(AssetFolder::MAX_DEPTH);

    actingAs($owner)
        ->post(route('assets.folders.store', $workspace), [
            'name' => 'One too deep',
            'parent_id' => $folder->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('renames a folder without moving it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $parent = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $folder = AssetFolder::factory()->inside($parent)->create(['created_by' => $owner->id]);

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $folder]), ['name' => 'Renamed'])
        ->assertRedirect();

    $folder->refresh();

    expect($folder->name)->toBe('Renamed')
        // The field was not sent, so it must not have been read as "move to the
        // top of the library".
        ->and($folder->parent_id)->toBe($parent->id);
});

it('moves a folder to the top of the library when parent_id is sent as null', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $parent = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $folder = AssetFolder::factory()->inside($parent)->create(['created_by' => $owner->id]);

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $folder]), ['parent_id' => null])
        ->assertRedirect();

    expect($folder->refresh()->parent_id)->toBeNull();
});

/**
 * The failure this prevents is silent: every folder in the detached subtree
 * still has a parent, so nothing looks broken — they are simply unreachable
 * from the top of the library forever.
 */
it('refuses to move a folder into its own descendant', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $parent = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    $child = AssetFolder::factory()->inside($parent)->create();

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $parent]), ['parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');

    expect($parent->refresh()->parent_id)->toBeNull();
});

it('refuses to move a folder into itself', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $folder]), ['parent_id' => $folder->id])
        ->assertSessionHasErrors('parent_id');
});

/**
 * Checking only the folder being moved would let a tall subtree be dropped near
 * the bound, producing exactly the over-deep tree the bound exists to prevent.
 */
it('refuses a move that would push the subtree past the depth bound', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    // A two-level subtree, at the top of the library.
    $subtree = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    AssetFolder::factory()->inside($subtree)->create();

    // A destination already at the bound minus one.
    $destination = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    for ($depth = 2; $depth < AssetFolder::MAX_DEPTH; $depth++) {
        $destination = AssetFolder::factory()->inside($destination)->create();
    }

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $subtree]), [
            'parent_id' => $destination->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('lets the creator delete an empty folder', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    actingAs($member)
        ->delete(route('assets.folders.destroy', [$workspace, $folder]))
        ->assertRedirect(route('assets.index', $workspace));

    expect(AssetFolder::find($folder->id))->toBeNull();
});

/**
 * A non-empty folder takes other people's work with it, so it is an
 * administrator's call rather than the creator's.
 */
it('refuses to let a member delete a folder that still has things in it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);
    Asset::factory()->inside($folder)->create();

    actingAs($member)
        ->delete(route('assets.folders.destroy', [$workspace, $folder]))
        ->assertForbidden();

    expect(AssetFolder::find($folder->id))->not->toBeNull();
});

it('lets an admin delete a folder and everything under it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $child = AssetFolder::factory()->inside($folder)->create();
    $asset = Asset::factory()->inside($child)->create();

    actingAs($owner)
        ->delete(route('assets.folders.destroy', [$workspace, $folder]))
        ->assertRedirect(route('assets.index', $workspace));

    expect(AssetFolder::find($folder->id))->toBeNull()
        ->and(AssetFolder::find($child->id))->toBeNull()
        ->and(Asset::find($asset->id))->toBeNull();
});

it('audits a folder deletion', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Contracts',
    ]);

    actingAs($owner)->delete(route('assets.folders.destroy', [$workspace, $folder]));

    $entry = AuditLog::where('workspace_id', $workspace->id)
        ->where('action', AuditAction::AssetFolderDeleted)
        ->sole();

    expect($entry->user_id)->toBe($owner->id)
        ->and($entry->old_values['name'])->toBe('Contracts');
});

it('refuses another member the right to rename a folder they did not create', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $curator = joinWorkspace($workspace);
    $other = joinWorkspace($workspace);

    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $curator->id,
    ]);

    actingAs($other)
        ->patch(route('assets.folders.update', [$workspace, $folder]), ['name' => 'Mine now'])
        ->assertForbidden();
});

it('lets an admin rename anybody’s folder', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $curator = joinWorkspace($workspace);

    $folder = AssetFolder::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $curator->id,
    ]);

    actingAs($owner)
        ->patch(route('assets.folders.update', [$workspace, $folder]), ['name' => 'Tidied'])
        ->assertRedirect();

    expect($folder->refresh()->name)->toBe('Tidied');
});

it('hides the library from guests', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    actingAs($guest)
        ->post(route('assets.folders.store', $workspace), ['name' => 'Brand'])
        ->assertNotFound();
});

it('refuses a folder in a workspace the viewer does not belong to', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $outsider = User::factory()->create();

    actingAs($outsider)
        ->post(route('assets.folders.store', $workspace), ['name' => 'Brand'])
        ->assertNotFound();
});
