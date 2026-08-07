<?php

use App\Enums\ProjectRole;

it('stars a project for one person and not for anybody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $colleague = joinWorkspace($workspace);
    addToProject($project, $colleague);

    $this->actingAs($owner)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    // A star is a fact about the person looking, not about the project: two
    // people on the same board disagree about it all day.
    expect($project->isStarredBy($owner))->toBeTrue();

    $project->unsetRelation('members');

    expect($project->isStarredBy($colleague))->toBeFalse();
});

it('keeps the date a second star would have moved', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    $first = $project->membershipFor($owner)->starred_at;

    $this->actingAs($owner)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    $project->unsetRelation('members');

    // The starred list is ordered by this date; a double-tapped star must not
    // reorder somebody's sidebar.
    expect($project->membershipFor($owner)->starred_at?->toIso8601String())
        ->toBe($first?->toIso8601String());
});

it('unstars again', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    $this->actingAs($owner)
        ->delete(route('projects.star.destroy', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    $project->unsetRelation('members');

    expect($project->isStarredBy($owner))->toBeFalse();
});

it('refuses a star from somebody who is not on the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    // The list a star reorders is the list of projects you are on, so there is
    // no row to write it to.
    $this->actingAs($outsider)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertForbidden();
});

it('tells each viewer whether they starred it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $colleague = joinWorkspace($workspace);
    addToProject($project, $colleague, ProjectRole::Member);

    $this->actingAs($owner)
        ->post(route('projects.star.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('projects.0.is_starred', true));

    $this->actingAs($colleague)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('projects.0.is_starred', false));
});
