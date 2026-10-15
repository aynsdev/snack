<?php

use App\Enums\ColumnCategory;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\CreateReleaseTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetReleaseTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListReleasesTool;
use App\Mcp\Tools\ListSprintsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateReleaseTool;
use App\Mcp\Tools\UpdateTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskLabel;

/**
 * Placing work over MCP: sprints, releases, parent epics and labels, on create
 * and update, one task or many — every name resolved within the project and
 * refused with the valid options when it matches nothing.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Pedicab Web App', 'key' => 'PWA']);
    addToProject($this->project, $this->member);

    $this->sprint = Sprint::factory()->active()->create(['project_id' => $this->project->id, 'name' => 'Sprint 7']);
    $this->release = Release::factory()->create(['project_id' => $this->project->id, 'name' => 'v3.2.1']);
    $this->epic = makeTask($this->project, $this->owner, ['title' => 'Release v3.2.1', 'type' => TaskType::Epic]);
    $this->label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'hotfix']);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the fare rounding', 'type' => TaskType::Bug]);
});

it('creates a task fully placed in one call', function () {
    $done = projectColumn($this->project, ColumnCategory::Done);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, [
            'project' => 'PWA',
            'title' => 'Fix the receipt total',
            'type' => 'bug',
            'status' => $done->name,
            'sprint' => 'active',
            'release' => 'v3.2.1',
            'parent' => $this->epic->reference(),
            'labels' => ['HOTFIX'],
            'due_on' => '2026-10-31',
            'story_points' => 3,
            'assignee' => 'me',
        ])
        ->assertOk()
        ->assertSee(['Created PWA-', 'release v3.2.1', 'Sprint 7']);

    $task = Task::query()->where('title', 'Fix the receipt total')->firstOrFail();

    expect($task->board_column_id)->toBe($done->id)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->sprint_id)->toBe($this->sprint->id)
        ->and($task->release_id)->toBe($this->release->id)
        ->and($task->parent_task_id)->toBe($this->epic->id)
        ->and($task->labels->pluck('id')->all())->toBe([$this->label->id])
        ->and($task->due_on->toDateString())->toBe('2026-10-31')
        ->and($task->story_points)->toBe(3)
        ->and($task->assignee_id)->toBe($this->member->id)
        ->and($task->reporter_id)->toBe($this->member->id);
});

it('refuses an unknown release on create with the valid options', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, ['project' => 'PWA', 'title' => 'Orphan', 'release' => 'v9.9.9'])
        ->assertHasErrors(['v3.2.1 (unreleased)', 'Nothing was created.']);

    expect(Task::query()->where('title', 'Orphan')->exists())->toBeFalse();
});

it('moves a task into a sprint, a release, and under an epic, and edits its labels', function () {
    $other = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'backend']);
    $this->task->labels()->attach($other);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, [
            'task' => $this->task->reference(),
            'sprint' => 'Sprint 7',
            'release' => 'v3.2.1',
            'parent' => $this->epic->reference(),
            'add_labels' => ['hotfix'],
            'remove_labels' => ['backend'],
        ])
        ->assertOk()
        ->assertSee(["Updated {$this->task->reference()}", 'sprint → Sprint 7', 'release → v3.2.1']);

    $task = $this->task->fresh();

    expect($task->sprint_id)->toBe($this->sprint->id)
        ->and($task->release_id)->toBe($this->release->id)
        ->and($task->parent_task_id)->toBe($this->epic->id)
        ->and($task->labels->pluck('name')->all())->toBe(['hotfix']);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'sprint' => 'backlog', 'release' => 'none', 'parent' => 'none'])
        ->assertOk();

    expect($this->task->fresh())
        ->sprint_id->toBeNull()
        ->release_id->toBeNull()
        ->parent_task_id->toBeNull();
});

it('puts a subtask under a story or a task', function () {
    $story = makeTask($this->project, $this->owner, ['title' => 'Driver payouts', 'type' => TaskType::Story]);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'parent' => $story->reference()])
        ->assertOk();

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, ['project' => 'PWA', 'title' => 'Round half up', 'parent' => $this->task->reference()])
        ->assertOk();

    $grandchild = Task::query()->where('title', 'Round half up')->firstOrFail();

    expect($this->task->fresh()->parent_task_id)->toBe($story->id)
        ->and($grandchild->parent_task_id)->toBe($this->task->id);
});

it('refuses a parent that would make a loop, or is elsewhere', function () {
    $child = makeTask($this->project, $this->owner, ['parent_task_id' => $this->task->id]);
    $grandchild = makeTask($this->project, $this->owner, ['parent_task_id' => $child->id]);
    $elsewhere = makeProject($this->workspace, $this->owner, ['name' => 'Mobile', 'key' => 'MOB']);
    addToProject($elsewhere, $this->member);
    $foreign = makeTask($elsewhere, $this->owner);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'parent' => $grandchild->reference()])
        ->assertHasErrors(['is a subtask of', 'Nothing was changed.']);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'parent' => $this->task->reference()])
        ->assertHasErrors(['A task cannot be its own parent.']);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'parent' => $foreign->reference()])
        ->assertHasErrors(['different project']);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->epic->reference(), 'parent' => $this->task->reference()])
        ->assertHasErrors(['An epic is always at the top']);

    expect($this->task->fresh()->parent_task_id)->toBeNull()
        ->and($this->epic->fresh()->parent_task_id)->toBeNull();
});

it('refuses an unknown sprint or label with the valid options', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'sprint' => 'Sprint 99'])
        ->assertHasErrors(['Sprint 7 (active)', 'backlog']);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'add_labels' => ['nope']])
        ->assertHasErrors(['hotfix']);
});

it('shows an epic with its subtasks, and lists them by parent', function () {
    $this->task->update(['parent_task_id' => $this->epic->id]);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->epic->reference()])
        ->assertOk()
        ->assertSee(['Child issues (0 of 1 done):', "{$this->task->reference()} — Fix the fare rounding"]);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->task->reference()])
        ->assertSee("Parent: {$this->epic->reference()}");

    SnackServer::actingAs($user)
        ->tool(ListTasksTool::class, ['project' => 'PWA', 'parent' => $this->epic->reference()])
        ->assertOk()
        ->assertSee('Fix the fare rounding')
        ->assertDontSee('Release v3.2.1');
});

it('lists tasks in a release', function () {
    $this->task->update(['release_id' => $this->release->id]);
    makeTask($this->project, $this->owner, ['title' => 'Unscheduled']);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ListTasksTool::class, ['project' => 'PWA', 'release' => 'v3.2.1'])
        ->assertOk()
        ->assertSee('Fix the fare rounding')
        ->assertDontSee('Unscheduled');
});

it('moves many tasks at once', function () {
    $second = makeTask($this->project, $this->owner, ['title' => 'Fix the map pin']);
    $done = projectColumn($this->project, ColumnCategory::Done);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTasksTool::class, [
            'tasks' => [$this->task->reference(), strtolower($second->reference())],
            'status' => $done->name,
            'release' => 'v3.2.1',
        ])
        ->assertOk()
        ->assertSee([$this->task->reference(), $second->reference()]);

    foreach ([$this->task, $second] as $task) {
        expect($task->fresh())
            ->board_column_id->toBe($done->id)
            ->release_id->toBe($this->release->id)
            ->completed_at->not->toBeNull();
    }
});

it('changes nothing in a bulk update when any task fails to resolve', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTasksTool::class, ['tasks' => [$this->task->reference(), 'PWA-9999'], 'release' => 'v3.2.1'])
        ->assertHasErrors(['Nothing was changed.', 'PWA-9999']);

    expect($this->task->fresh()->release_id)->toBeNull();
});

it('lists sprints', function () {
    Sprint::factory()->completed()->create(['project_id' => $this->project->id, 'name' => 'Sprint 6']);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ListSprintsTool::class, ['project' => 'PWA'])
        ->assertOk()
        ->assertSee(['Sprint 7 [active]', 'Backlog:'])
        ->assertDontSee('Sprint 6');
});

it('plans, lists, and ships a release as a project lead', function () {
    [$user] = connectAs($this->owner, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CreateReleaseTool::class, ['project' => 'PWA', 'name' => 'v3.3.0', 'description' => 'Fare fixes', 'target_date' => '2026-11-15'])
        ->assertOk()
        ->assertSee('Created release v3.3.0');

    SnackServer::actingAs($user)
        ->tool(CreateReleaseTool::class, ['project' => 'PWA', 'name' => 'V3.3.0'])
        ->assertHasErrors(['already has a release']);

    $this->task->update(['release_id' => $this->release->id]);

    SnackServer::actingAs($user)
        ->tool(GetReleaseTool::class, ['project' => 'PWA', 'release' => 'v3.2.1'])
        ->assertOk()
        ->assertSee(['0 of 1 done', "[ ] {$this->task->reference()} — Fix the fare rounding"]);

    SnackServer::actingAs($user)
        ->tool(UpdateReleaseTool::class, ['project' => 'PWA', 'release' => 'v3.2.1', 'released' => true, 'released_on' => '2026-10-14'])
        ->assertOk()
        ->assertSee('released Oct 14, 2026');

    expect($this->release->fresh()->released_at->toDateString())->toBe('2026-10-14');

    SnackServer::actingAs($user)
        ->tool(ListReleasesTool::class, ['project' => 'PWA', 'status' => 'planned'])
        ->assertOk()
        ->assertSee('v3.3.0')
        ->assertDontSee('v3.2.1');
});

it('keeps release planning to project leads', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CreateReleaseTool::class, ['project' => 'PWA', 'name' => 'v4.0.0'])
        ->assertHasErrors(['Only project leads']);

    SnackServer::actingAs($user)
        ->tool(UpdateReleaseTool::class, ['project' => 'PWA', 'release' => 'v3.2.1', 'released' => true])
        ->assertHasErrors(['Only project leads']);

    expect($this->release->fresh()->released_at)->toBeNull();
});

it('needs a story, task, or bug as a subtask’s parent', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, ['project' => 'PWA', 'title' => 'Loose piece', 'type' => 'subtask'])
        ->assertHasErrors(['A subtask always has a parent', 'Nothing was created.']);

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, ['project' => 'PWA', 'title' => 'Under the epic', 'type' => 'subtask', 'parent' => $this->epic->reference()])
        ->assertHasErrors(['not straight under an epic']);

    SnackServer::actingAs($user)
        ->tool(CreateTaskTool::class, ['project' => 'PWA', 'title' => 'Round half up', 'type' => 'subtask', 'parent' => $this->task->reference()])
        ->assertOk();

    expect(Task::query()->where('title', 'Round half up')->sole())
        ->type->toBe(TaskType::Subtask)
        ->parent_task_id->toBe($this->task->id);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->task->reference()])
        ->assertSee(['Subtasks (0 of 1 done):', 'Round half up', 'subtask']);
});

it('declares parent in the schemas clients read', function () {
    foreach ([CreateTaskTool::class, UpdateTaskTool::class, UpdateTasksTool::class] as $tool) {
        $schema = app($tool)->toArray()['inputSchema']['properties'];

        expect($schema)->toHaveKey('parent')
            ->and($schema['parent']['description'])->toContain('subtask');
    }

    expect(app(UpdateTaskTool::class)->toArray()['inputSchema']['properties']['parent']['description'])->toContain('"none"');
});

it('puts several tasks under one parent at once', function () {
    $second = makeTask($this->project, $this->owner, ['title' => 'Fix the map pin']);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTasksTool::class, ['tasks' => [$this->task->reference(), $second->reference()], 'parent' => $this->epic->reference()])
        ->assertOk()
        ->assertSee("parent → {$this->epic->reference()}");

    expect($this->task->fresh()->parent_task_id)->toBe($this->epic->id)
        ->and($second->fresh()->parent_task_id)->toBe($this->epic->id);
});
