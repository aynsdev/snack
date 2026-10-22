<?php

use App\Models\Release;
use App\Models\Sprint;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner);
    $this->base = "/api/v1/w/{$this->workspace->slug}/projects/{$this->project->slug}";
});

it('plans, edits, starts and completes a sprint', function () {
    $token = mobileToken($this->owner);

    $id = $this->withToken($token)
        ->postJson("{$this->base}/sprints", ['name' => 'Sprint 1', 'goal' => 'Ship login'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Sprint 1')
        ->assertJsonPath('data.state', 'planned')
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("{$this->base}/sprints/{$id}", ['goal' => 'Ship login and signup'])
        ->assertOk()
        ->assertJsonPath('data.goal', 'Ship login and signup');

    makeTask($this->project, $this->owner, ['sprint_id' => $id]);

    $this->withToken($token)
        ->postJson("{$this->base}/sprints/{$id}/start")
        ->assertOk()
        ->assertJsonPath('data.state', 'active');

    $this->withToken($token)
        ->postJson("{$this->base}/sprints/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('meta.completed', 0)
        ->assertJsonPath('meta.moved', 1);
});

it('deletes a planned sprint but not a started one', function () {
    $token = mobileToken($this->owner);
    $planned = Sprint::factory()->create(['project_id' => $this->project->id]);
    $active = Sprint::factory()->active()->create(['project_id' => $this->project->id]);

    $this->withToken($token)
        ->deleteJson("{$this->base}/sprints/{$active->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sprint');

    $this->withToken($token)
        ->deleteJson("{$this->base}/sprints/{$planned->id}")
        ->assertNoContent();

    expect(Sprint::find($planned->id))->toBeNull();
});

it('refuses sprint planning to a plain member', function () {
    $member = joinWorkspace($this->workspace);
    addToProject($this->project, $member);

    $this->withToken(mobileToken($member))
        ->postJson("{$this->base}/sprints", ['name' => 'Sprint 1'])
        ->assertForbidden();
});

it('lists and shows releases', function () {
    $release = Release::factory()->create(['project_id' => $this->project->id, 'name' => '1.4.0']);
    $task = makeTask($this->project, $this->owner, ['release_id' => $release->id]);
    $unscheduled = makeTask($this->project, $this->owner);

    $token = mobileToken($this->owner);

    $this->withToken($token)
        ->getJson("{$this->base}/releases")
        ->assertOk()
        ->assertJsonPath('data.releases.0.name', '1.4.0')
        ->assertJsonPath('data.releases.0.task_count', 1)
        ->assertJsonPath('data.can_manage', true);

    $this->withToken($token)
        ->getJson("{$this->base}/releases/{$release->id}")
        ->assertOk()
        ->assertJsonPath('data.release.tasks.0.id', $task->id)
        ->assertJsonPath('data.schedulable.0.id', $unscheduled->id);
});

it('creates, updates, releases and cancels a release', function () {
    $token = mobileToken($this->owner);

    $id = $this->withToken($token)
        ->postJson("{$this->base}/releases", ['name' => '2.0.0', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'])
        ->assertCreated()
        ->assertJsonPath('data.name', '2.0.0')
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("{$this->base}/releases/{$id}", ['notes' => 'Big one'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Big one');

    $this->withToken($token)
        ->postJson("{$this->base}/releases/{$id}/released")
        ->assertOk()
        ->assertJsonPath('data.state', 'released');

    $this->withToken($token)
        ->deleteJson("{$this->base}/releases/{$id}/released")
        ->assertOk()
        ->assertJsonPath('data.released_at', null);

    $this->withToken($token)
        ->deleteJson("{$this->base}/releases/{$id}")
        ->assertNoContent();

    expect(Release::find($id))->toBeNull();
});

it('refuses a duplicate release name', function () {
    Release::factory()->create(['project_id' => $this->project->id, 'name' => '1.0.0']);

    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/releases", ['name' => '1.0.0'])
        ->assertJsonValidationErrors('name');
});
