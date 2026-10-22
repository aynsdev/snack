<?php

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\ProjectGroup;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists the workspace projects with groups and archived ones', function () {
    $archived = makeProject($this->workspace, $this->owner, ['name' => 'Old Board']);
    $archived->update(['is_archived' => true]);
    $group = ProjectGroup::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/projects")
        ->assertOk()
        ->assertJsonCount(1, 'data.projects')
        ->assertJsonPath('data.projects.0.slug', $this->project->slug)
        ->assertJsonPath('data.archived.0.slug', $archived->slug)
        ->assertJsonPath('data.groups.0.id', $group->id)
        ->assertJsonPath('data.can_create', true);
});

it('hides the project list from a non-member of the workspace', function () {
    $this->withToken(mobileToken(User::factory()->create()))
        ->getJson("{$this->base}/projects")
        ->assertNotFound();
});

it('creates a project', function () {
    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/projects", ['name' => 'Mobile App', 'key' => 'MOB', 'uses_sprints' => false])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Mobile App')
        ->assertJsonPath('data.key', 'MOB')
        ->assertJsonPath('data.uses_sprints', false);
});

it('shows the settings of a project', function () {
    $outsider = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/projects/{$this->project->slug}/settings")
        ->assertOk()
        ->assertJsonPath('data.project.id', $this->project->id)
        ->assertJsonPath('data.members.0.user.id', $this->owner->id)
        ->assertJsonPath('data.addable.0.id', $outsider->id)
        ->assertJsonPath('data.can_manage', true);
});

it('updates a project and answers with its new slug', function () {
    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/projects/{$this->project->slug}", ['name' => 'Renamed Board'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Board')
        ->assertJsonPath('data.slug', $this->project->refresh()->slug);
});

it('refuses an update from a plain project member', function () {
    $member = joinWorkspace($this->workspace);
    addToProject($this->project, $member);

    $this->withToken(mobileToken($member))
        ->patchJson("{$this->base}/projects/{$this->project->slug}", ['name' => 'Nope'])
        ->assertForbidden();
});

it('moves a project onto a group', function () {
    $group = ProjectGroup::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/projects/{$this->project->slug}/group", ['project_group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('data.project_group_id', $group->id);
});

it('archives and restores a project', function () {
    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->postJson("{$this->base}/projects/{$this->project->slug}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $this->withToken($token)
        ->deleteJson("{$this->base}/projects/{$this->project->slug}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);
});

it('stars and unstars a project for the viewer', function () {
    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->postJson("{$this->base}/projects/{$this->project->slug}/star")
        ->assertNoContent();

    expect($this->project->membershipFor($this->owner)->starred_at)->not->toBeNull();

    $this->withToken($token)
        ->deleteJson("{$this->base}/projects/{$this->project->slug}/star")
        ->assertNoContent();

    expect($this->project->membershipFor($this->owner)->starred_at)->toBeNull();
});

it('lets a member join and leave a project', function () {
    $member = joinWorkspace($this->workspace);
    $token = mobileToken($member);

    $this->withToken($token)
        ->postJson("{$this->base}/projects/{$this->project->slug}/membership")
        ->assertCreated()
        ->assertJsonPath('data.is_member', true);

    $this->withToken($token)
        ->deleteJson("{$this->base}/projects/{$this->project->slug}/membership")
        ->assertNoContent();

    expect($this->project->refresh()->hasMember($member))->toBeFalse();
});

it('refuses to let the last lead leave', function () {
    $this->withToken(mobileToken($this->owner))
        ->deleteJson("{$this->base}/projects/{$this->project->slug}/membership")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('membership');
});

it('manages project groups', function () {
    $token = mobileToken($this->owner);

    $first = $this->withToken($token)
        ->postJson("{$this->base}/project-groups", ['name' => 'Client work'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Client work')
        ->json('data.id');

    $second = $this->withToken($token)
        ->postJson("{$this->base}/project-groups", ['name' => 'Internal'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("{$this->base}/project-groups/{$first}", ['name' => 'Clients'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Clients');

    $this->withToken($token)
        ->postJson("{$this->base}/project-groups/{$second}/move", ['direction' => 'up'])
        ->assertOk()
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.1.id', $first);

    $this->withToken($token)
        ->deleteJson("{$this->base}/project-groups/{$first}")
        ->assertNoContent();

    expect(ProjectGroup::find($first))->toBeNull();
});

it('refuses group curation to a guest', function () {
    $guest = joinWorkspace($this->workspace, role: WorkspaceRole::Guest);

    $this->withToken(mobileToken($guest))
        ->postJson("{$this->base}/project-groups", ['name' => 'Mine'])
        ->assertForbidden();
});

it('adds, re-roles and removes project members', function () {
    $token = mobileToken($this->owner);
    $colleague = joinWorkspace($this->workspace);

    $this->withToken($token)
        ->postJson("{$this->base}/projects/{$this->project->slug}/members", ['user_ids' => [$colleague->id]])
        ->assertCreated()
        ->assertJsonPath('meta.added', 1)
        ->assertJsonCount(2, 'data');

    $membership = $this->project->membershipFor($colleague);

    $this->withToken($token)
        ->patchJson("{$this->base}/projects/{$this->project->slug}/members/{$membership->id}", ['role' => ProjectRole::Lead->value])
        ->assertOk()
        ->assertJsonPath('data.role', 'lead')
        ->assertJsonPath('data.user.id', $colleague->id);

    $this->withToken($token)
        ->deleteJson("{$this->base}/projects/{$this->project->slug}/members/{$membership->id}")
        ->assertNoContent();

    expect($this->project->refresh()->hasMember($colleague))->toBeFalse();
});

it('refuses to demote the last lead', function () {
    $membership = $this->project->membershipFor($this->owner);

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/projects/{$this->project->slug}/members/{$membership->id}", ['role' => 'member'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});
