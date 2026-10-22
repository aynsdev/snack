<?php

use App\Enums\ColumnCategory;
use App\Enums\WorkspaceRole;
use App\Models\BoardColumn;
use App\Models\TaskLabel;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner, ['uses_sprints' => false]);
    $this->base = "/api/v1/w/{$this->workspace->slug}/projects/{$this->project->slug}";
});

it('shows the board with its columns and cards', function () {
    $task = makeTask($this->project, $this->owner);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}?task={$task->reference()}")
        ->assertOk()
        ->assertJsonPath('data.project.id', $this->project->id)
        ->assertJsonPath('data.tasks.0.id', $task->id)
        ->assertJsonPath('data.open_task.id', $task->id)
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonStructure(['data' => ['columns', 'sprint', 'planned_sprints', 'backlog_count', 'members', 'labels', 'releases', 'can_contribute']]);
});

it('hides a board from someone outside the workspace', function () {
    $this->withToken(mobileToken(User::factory()->create()))
        ->getJson($this->base)
        ->assertNotFound();
});

it('hides a board from a guest who is not on it', function () {
    $guest = joinWorkspace($this->workspace, role: WorkspaceRole::Guest);

    $this->withToken(mobileToken($guest))
        ->getJson($this->base)
        ->assertNotFound();
});

it('shows the backlog of a sprint project', function () {
    $scrum = makeProject($this->workspace, $this->owner, ['name' => 'Scrum Board', 'uses_sprints' => true]);
    $task = makeTask($scrum, $this->owner);

    $this->withToken(mobileToken($this->owner))
        ->getJson("/api/v1/w/{$this->workspace->slug}/projects/{$scrum->slug}/backlog")
        ->assertOk()
        ->assertJsonPath('data.tasks.0.id', $task->id)
        ->assertJsonStructure(['data' => ['project', 'columns', 'sprints', 'members', 'labels', 'releases', 'can_contribute', 'can_manage']]);
});

it('has no backlog for a kanban project', function () {
    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/backlog")
        ->assertNotFound();
});

it('summarises a project', function () {
    makeTask($this->project, $this->owner);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/summary")
        ->assertOk()
        ->assertJsonPath('data.recent.created', 1)
        ->assertJsonCount(5, 'data.priorities')
        ->assertJsonPath('data.activity.0.title', $this->project->tasks()->first()->title)
        ->assertJsonStructure(['data' => ['project', 'members', 'statuses', 'types', 'can_manage']]);
});

it('adds, edits and removes a column', function () {
    $token = mobileToken($this->owner);
    $done = projectColumn($this->project, ColumnCategory::Done);

    $id = $this->withToken($token)
        ->postJson("{$this->base}/columns", ['name' => 'Review', 'category' => 'started', 'before_column_id' => $done->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Review')
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("{$this->base}/columns/{$id}", ['wip_limit' => 3])
        ->assertOk()
        ->assertJsonPath('data.wip_limit', 3);

    $this->withToken($token)
        ->deleteJson("{$this->base}/columns/{$id}")
        ->assertNoContent();

    expect(BoardColumn::find($id))->toBeNull();
});

it('refuses column changes to a plain member', function () {
    $member = joinWorkspace($this->workspace);
    addToProject($this->project, $member);

    $this->withToken(mobileToken($member))
        ->postJson("{$this->base}/columns", ['name' => 'Review', 'category' => 'started'])
        ->assertForbidden();
});

it('adds, edits and removes a label', function () {
    $token = mobileToken($this->owner);

    $id = $this->withToken($token)
        ->postJson("{$this->base}/labels", ['name' => 'backend', 'color' => 'blue'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'backend')
        ->json('data.id');

    $this->withToken($token)
        ->patchJson("{$this->base}/labels/{$id}", ['color' => 'red'])
        ->assertOk()
        ->assertJsonPath('data.color', 'red');

    $this->withToken($token)
        ->postJson("{$this->base}/labels", ['name' => 'backend', 'color' => 'green'])
        ->assertJsonValidationErrors('name');

    $this->withToken($token)
        ->deleteJson("{$this->base}/labels/{$id}")
        ->assertNoContent();

    expect(TaskLabel::find($id))->toBeNull();
});
