<?php

use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The top of the library is everything in it, however deeply it is filed.
 *
 * A library you have to walk is a library where "do we have a dark version of
 * the logo" answers "somewhere, probably" — see
 * AssetLibraryController::subtreeIds.
 */
it('shows every file in the workspace at the top of the library', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    Asset::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Company one-pager']);

    $brand = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand']);
    $logos = AssetFolder::factory()->inside($brand)->create(['name' => 'Logos']);

    Asset::factory()->inside($brand)->create(['name' => 'Colour palette']);
    Asset::factory()->inside($logos)->create(['name' => 'Primary logo']);

    actingAs($owner)
        ->get(route('assets.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assets/index')
            ->where('folder', null)
            ->has('assets', 3)
            ->where(
                'assets',
                fn ($assets) => collect($assets)->pluck('name')->sort()->values()->all() === [
                    'Colour palette', 'Company one-pager', 'Primary logo',
                ],
            )
            // The tree is sent whole regardless of where you are standing.
            ->has('folders', 2),
        );
});

/**
 * A folder narrows the listing rather than walling things off: opening one shows
 * less than the level above, never something that level could not show.
 */
it('shows a folder’s whole subtree, with its breadcrumb', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $brand = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand']);
    $logos = AssetFolder::factory()->inside($brand)->create(['name' => 'Logos']);
    $legal = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Legal']);

    Asset::factory()->inside($brand)->create(['name' => 'Colour palette']);
    Asset::factory()->inside($logos)->create(['name' => 'Primary logo']);

    // Neither of these is under Brand, so neither may appear.
    Asset::factory()->inside($legal)->create(['name' => 'Master services agreement']);
    Asset::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Company one-pager']);

    actingAs($owner)
        ->get(route('assets.folders.show', [$workspace, $brand]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder.name', 'Brand')
            ->has('assets', 2)
            ->where(
                'assets',
                fn ($assets) => collect($assets)->pluck('name')->sort()->values()->all() === [
                    'Colour palette', 'Primary logo',
                ],
            )
            ->has('breadcrumb', 1)
            ->where('breadcrumb.0.name', 'Brand'),
        );
});

it('shows the deepest shelf on its own, with the whole breadcrumb', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $brand = AssetFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand']);
    $logos = AssetFolder::factory()->inside($brand)->create(['name' => 'Logos']);

    Asset::factory()->inside($logos)->create(['name' => 'Primary logo']);
    Asset::factory()->inside($brand)->create(['name' => 'Colour palette']);

    actingAs($owner)
        ->get(route('assets.folders.show', [$workspace, $logos]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder.name', 'Logos')
            ->has('assets', 1)
            ->where('assets.0.name', 'Primary logo')
            ->has('breadcrumb', 2)
            ->where('breadcrumb.0.name', 'Brand')
            ->where('breadcrumb.1.name', 'Logos'),
        );
});

/**
 * Somebody typing a name does not know which shelf the thing is on — that is
 * why they are typing. Confining the search to the folder they happen to be
 * standing in front of would answer a question nobody asked.
 */
it('searches across the whole library, not just the open shelf', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $brand = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    $legal = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);

    Asset::factory()->inside($legal)->create(['name' => 'Master services agreement']);
    Asset::factory()->inside($brand)->create(['name' => 'Primary logo']);

    actingAs($owner)
        ->get(route('assets.folders.show', [$workspace, $brand]).'?q=services')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('assets', 1)
            ->where('assets.0.name', 'Master services agreement'),
        );
});

it('matches a search case-insensitively and on a fragment', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    Asset::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brand Guidelines 2026']);

    actingAs($owner)
        ->get(route('assets.index', $workspace).'?q=guide')
        ->assertInertia(fn ($page) => $page->has('assets', 1));
});

it('filters by kind through the file behind the asset', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    Asset::factory()->withFile('image/png')->create([
        'workspace_id' => $workspace->id,
        'name' => 'Logo',
    ]);
    Asset::factory()->withFile('application/pdf')->create([
        'workspace_id' => $workspace->id,
        'name' => 'Contract',
    ]);

    actingAs($owner)
        ->get(route('assets.index', $workspace).'?kind=pdf')
        ->assertInertia(fn ($page) => $page
            ->has('assets', 1)
            ->where('assets.0.name', 'Contract'),
        );
});

it('sends the file behind an asset as signed urls, never a path', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    Asset::factory()->withFile('image/png', 'logo-final.png')->create([
        'workspace_id' => $workspace->id,
        'name' => 'Primary logo',
    ]);

    actingAs($owner)
        ->get(route('assets.index', $workspace))
        ->assertInertia(fn ($page) => $page
            // Two names: what the library calls it, and what was uploaded.
            ->where('assets.0.name', 'Primary logo')
            ->where('assets.0.file.name', 'logo-final.png')
            ->where('assets.0.file.kind', 'image')
            ->has('assets.0.file.download_url')
            ->missing('assets.0.file.path')
            ->missing('assets.0.file.disk'),
        );
});

it('does not show another workspace’s library', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $elsewhere = makeWorkspace();

    Asset::factory()->create(['workspace_id' => $elsewhere->id, 'name' => 'Not yours']);
    AssetFolder::factory()->create(['workspace_id' => $elsewhere->id]);

    actingAs($owner)
        ->get(route('assets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('assets', 0)->has('folders', 0));
});

it('404s a folder belonging to another workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $elsewhere = AssetFolder::factory()->create();

    // Refused by scoped route binding, before the policy is ever consulted.
    actingAs($owner)
        ->get(route('assets.folders.show', [$workspace, $elsewhere]))
        ->assertNotFound();
});

it('hides the library from guests', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    actingAs($guest)->get(route('assets.index', $workspace))->assertNotFound();
});

it('hides the library from people outside the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();

    actingAs(User::factory()->create())
        ->get(route('assets.index', $workspace))
        ->assertNotFound();
});

it('tells the client what this viewer may do', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $mine = Asset::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'name' => 'A',
    ]);
    $theirs = Asset::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'B',
    ]);

    actingAs($member)
        ->get(route('assets.index', $workspace))
        ->assertInertia(function ($page) use ($mine, $theirs) {
            $assets = collect($page->toArray()['props']['assets'])->keyBy('id');

            expect($assets[$mine->id]['can_manage'])->toBeTrue()
                ->and($assets[$theirs->id]['can_manage'])->toBeFalse();

            $page->where('abilities.create', true)
                ->where('abilities.create_folder', true);
        });
});

/**
 * The library's page is one query for the tree and one for the shelf, plus the
 * viewer and their membership. Asking the policy per row is what would quietly
 * turn that into one query per tile — see AssetLibraryController::render, which
 * loads the memberships once for exactly this reason.
 */
it('does not issue a query per row', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $shelf = AssetFolder::factory()->create(['workspace_id' => $workspace->id]);
    AssetFolder::factory()->count(4)->inside($shelf)->create();
    Asset::factory()->count(12)->withFile()->create(['workspace_id' => $workspace->id]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    actingAs($owner)->get(route('assets.index', $workspace))->assertOk();

    expect($queries)->toBeLessThan(20);
});
