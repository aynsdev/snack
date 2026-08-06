<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Support\Positions;

it('creates a group and puts it at the end of the list', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->post(route('project-groups.store', $workspace->slug), ['name' => 'Platform'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('project-groups.store', $workspace->slug), ['name' => 'Client work'])
        ->assertRedirect();

    // Order of creation, not the alphabet: the point of a position column is
    // that the workspace decides.
    expect($workspace->projectGroups()->ordered()->pluck('name')->all())
        ->toBe(['Platform', 'Client work']);

    expect(AuditLog::where('action', AuditAction::ProjectGroupCreated)->count())->toBe(2);
});

it('refuses a second group with the same name, whatever its case', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    ProjectGroup::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Platform']);

    $this->actingAs($owner)
        ->post(route('project-groups.store', $workspace->slug), ['name' => 'platform'])
        ->assertSessionHasErrors('name');

    expect($workspace->projectGroups()->count())->toBe(1);
});

it('lets one workspace reuse a name another workspace has taken', function () {
    [$first] = makeWorkspaceWithOwner();
    [$second, $secondOwner] = makeWorkspaceWithOwner();

    ProjectGroup::factory()->create(['workspace_id' => $first->id, 'name' => 'Platform']);

    $this->actingAs($secondOwner)
        ->post(route('project-groups.store', $second->slug), ['name' => 'Platform'])
        ->assertSessionHasNoErrors();

    // Uniqueness is per workspace, not global.
    expect($second->projectGroups()->count())->toBe(1)
        ->and($first->projectGroups()->count())->toBe(1);
});

it('refuses a guest the right to arrange the project list', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->post(route('project-groups.store', $workspace->slug), ['name' => 'Secret'])
        ->assertForbidden();
});

it('renames a group', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Platform']);

    $this->actingAs($owner)
        ->patch(route('project-groups.update', [$workspace->slug, $group->id]), ['name' => 'Infrastructure'])
        ->assertRedirect();

    expect($group->refresh()->name)->toBe('Infrastructure')
        ->and(AuditLog::where('action', AuditAction::ProjectGroupUpdated)->exists())->toBeTrue();
});

it('will not let one workspace address another workspace group', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$other] = makeWorkspaceWithOwner();

    $foreign = ProjectGroup::factory()->create(['workspace_id' => $other->id]);

    // Scoped route binding: the id is real, but it is not this workspace's.
    $this->actingAs($owner)
        ->patch(route('project-groups.update', [$workspace->slug, $foreign->id]), ['name' => 'Mine now'])
        ->assertNotFound();

    expect($foreign->refresh()->name)->not->toBe('Mine now');
});

it('moves a group up and down the list', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    ProjectGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'First',
        'position' => Positions::GAP,
    ]);

    $second = ProjectGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Second',
        'position' => Positions::GAP * 2,
    ]);

    $this->actingAs($owner)
        ->post(route('project-groups.move', [$workspace->slug, $second->id]), ['direction' => 'up'])
        ->assertRedirect();

    expect($workspace->projectGroups()->ordered()->pluck('name')->all())->toBe(['Second', 'First']);

    $this->actingAs($owner)
        ->post(route('project-groups.move', [$workspace->slug, $second->id]), ['direction' => 'down'])
        ->assertRedirect();

    expect($workspace->projectGroups()->ordered()->pluck('name')->all())->toBe(['First', 'Second']);

    // Already last: a no-op rather than an error, because the control that sent
    // it is disabled and a request that raced past it wants a state we are in.
    $this->actingAs($owner)
        ->post(route('project-groups.move', [$workspace->slug, $second->id]), ['direction' => 'down'])
        ->assertRedirect();

    expect($workspace->projectGroups()->ordered()->pluck('name')->all())->toBe(['First', 'Second']);
});

it('deletes a group without deleting the projects in it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id]);

    $project = makeProject($workspace, $owner);
    $project->update(['project_group_id' => $group->id]);

    $this->actingAs($owner)
        ->delete(route('project-groups.destroy', [$workspace->slug, $group->id]))
        ->assertRedirect();

    expect(ProjectGroup::find($group->id))->toBeNull();

    // The shelf is gone; the book is on the floor, not burnt.
    expect(Project::find($project->id))->not->toBeNull()
        ->and($project->refresh()->project_group_id)->toBeNull();
});

it('creates a project straight into a group', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)
        ->post(route('projects.store', $workspace->slug), [
            'name' => 'Pedicab Web Api',
            'project_group_id' => $group->id,
        ])
        ->assertRedirect();

    expect(Project::firstWhere('name', 'Pedicab Web Api')->project_group_id)->toBe($group->id);
});

it('moves a project between groups and back out of them', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id]);
    $project = makeProject($workspace, $owner);

    $route = route('projects.group.update', [$workspace->slug, $project->slug]);

    $this->actingAs($owner)
        ->patch($route, ['project_group_id' => $group->id])
        ->assertRedirect();

    expect($project->refresh()->project_group_id)->toBe($group->id);

    // Null is the whole of "take it out of its group" — there is no second
    // endpoint for ungrouping.
    $this->actingAs($owner)
        ->patch($route, ['project_group_id' => null])
        ->assertRedirect();

    expect($project->refresh()->project_group_id)->toBeNull();
});

it('refuses to file a project onto another workspace group', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$other] = makeWorkspaceWithOwner();

    $foreign = ProjectGroup::factory()->create(['workspace_id' => $other->id]);
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->patch(route('projects.group.update', [$workspace->slug, $project->slug]), [
            'project_group_id' => $foreign->id,
        ])
        ->assertSessionHasErrors('project_group_id');

    expect($project->refresh()->project_group_id)->toBeNull();
});

it('refuses to move a project the mover does not lead', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id]);
    $project = makeProject($workspace, $owner);

    // A workspace member who is not on the project: they may make groups, but
    // moving somebody else's project between them is a change to that project.
    $outsider = joinWorkspace($workspace);

    $this->actingAs($outsider)
        ->patch(route('projects.group.update', [$workspace->slug, $project->slug]), [
            'project_group_id' => $group->id,
        ])
        ->assertForbidden();

    expect($project->refresh()->project_group_id)->toBeNull();
});

it('sends the groups and each project’s own group to the list page', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $group = ProjectGroup::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Platform']);

    $grouped = makeProject($workspace, $owner, ['name' => 'Pedicab Web Api']);
    $grouped->update(['project_group_id' => $group->id]);

    makeProject($workspace, $owner, ['name' => 'Loose Ends']);

    $this->actingAs($owner)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canManageGroups', true)
            ->has('groups', 1)
            ->where('groups.0.name', 'Platform')
            // Sent flat, each project naming its group: the client buckets.
            ->has('projects', 2)
            ->where('projects.0.name', 'Loose Ends')
            ->where('projects.0.project_group_id', null)
            ->where('projects.1.name', 'Pedicab Web Api')
            ->where('projects.1.project_group_id', $group->id)
        );
});

it('tells a guest they may not arrange the list', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    addToProject($project, $guest);

    $this->actingAs($guest)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManageGroups', false));
});
