<?php

use App\Enums\ColumnCategory;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;

it('creates a project with a board, a key, and its creator as lead', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->post(route('projects.store', $workspace->slug), [
            'name' => 'Pedicab Web Api',
            'uses_sprints' => true,
        ])
        ->assertRedirect();

    $project = Project::firstWhere('name', 'Pedicab Web Api');

    // Derived from the initials, which is what somebody would have typed.
    expect($project->key)->toBe('PWA')
        ->and($project->slug)->toBe('pedicab-web-api');

    // A project with no columns cannot hold a task, and one with no members is
    // a board nobody may work — so both ship with it.
    expect($project->columns()->count())->toBe(3)
        ->and($project->columns()->where('category', ColumnCategory::Done->value)->exists())->toBeTrue()
        ->and($project->roleFor($owner))->toBe(ProjectRole::Lead);
});

it('honours a key the creator typed, and refuses one already in use', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->post(route('projects.store', $workspace->slug), ['name' => 'Payments', 'key' => 'pay'])
        ->assertRedirect();

    expect(Project::firstWhere('name', 'Payments')->key)->toBe('PAY');

    $this->actingAs($owner)
        ->post(route('projects.store', $workspace->slug), ['name' => 'Other', 'key' => 'PAY'])
        ->assertSessionHasErrors('key');
});

it('gives two projects in one workspace distinct keys', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $first = makeProject($workspace, $owner, ['name' => 'Pedicab Web Api']);
    $second = makeProject($workspace, $owner, ['name' => 'Pedicab Web Api']);

    // Same name, same derived key — the second has to be pushed aside, because
    // a reference that matches two projects means nothing.
    expect($first->key)->toBe('PWA')
        ->and($second->key)->toBe('PWA2');
});

it('refuses a guest the right to create a project', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->post(route('projects.store', $workspace->slug), ['name' => 'Secret'])
        ->assertForbidden();
});

it('lets anyone in the workspace read a board, and only members work it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    // Reading is open: a board nobody outside the team can look at is how a
    // company ends up asking in #general what shipped.
    $this->actingAs($outsider)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canContribute', false));

    $this->actingAs($outsider)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Not mine to file',
            'type' => 'story',
            'priority' => 'medium',
        ])
        ->assertForbidden();
});

it('hides a project from a guest who was not added to it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    // Not a 403: a guest must not learn that a board by that name exists.
    $this->actingAs($guest)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertNotFound();

    addToProject($project, $guest);

    $this->actingAs($guest)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertOk();
});

it('regenerates the slug on a rename but leaves the key alone', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner, ['name' => 'Pedicab Web Api']);

    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'name' => 'Rider App',
        ])
        ->assertRedirect();

    $project->refresh();

    // The key is inside every reference anyone has ever quoted, so a rename
    // must not invalidate them all at once.
    expect($project->slug)->toBe('rider-app')
        ->and($project->key)->toBe('PWA');
});

it('stores only known card fields and answers with the defaults filled in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'card_fields' => ['story_points' => true, 'assignee' => false],
        ])
        ->assertRedirect();

    $project->refresh();

    // Compared without regard to order: the column is jsonb, which does not
    // preserve the order the keys were written in.
    expect($project->card_fields)->toEqualCanonicalizing(['story_points' => true, 'assignee' => false])
        // Anything the project has not decided still arrives resolved, so a
        // toggle added in a later release is not frozen off for everybody.
        ->and($project->cardFields()['type'])->toBeTrue()
        ->and($project->cardFields()['assignee'])->toBeFalse();

    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'card_fields' => ['not_a_field' => true],
        ])
        ->assertSessionHasErrors('card_fields');
});

it('refuses a member who is not a lead the right to change the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $member = joinWorkspace($workspace);
    addToProject($project, $member);

    $this->actingAs($member)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), ['name' => 'Mine now'])
        ->assertForbidden();
});

it('makes an archived project read-only without losing anything', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->post(route('projects.archive.store', [$workspace->slug, $project->slug]))
        ->assertRedirect(route('projects.index', $workspace->slug));

    expect($project->refresh()->is_archived)->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Too late',
            'type' => 'story',
            'priority' => 'medium',
        ])
        ->assertForbidden();

    // Restoring has to work on a project that is, by definition, archived —
    // which is why it is not routed through the ordinary update rule.
    $this->actingAs($owner)
        ->delete(route('projects.archive.destroy', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    expect($project->refresh()->is_archived)->toBeFalse();
});

it('lists the workspace projects, and only the joined ones for a guest', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $open = makeProject($workspace, $owner, ['name' => 'Open Project']);
    makeProject($workspace, $owner, ['name' => 'Other Project']);

    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    addToProject($open, $guest);

    $this->actingAs($owner)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('projects', 2));

    $this->actingAs($guest)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('projects', 1)
            ->where('projects.0.name', 'Open Project'));
});
