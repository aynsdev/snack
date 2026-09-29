<?php

use App\Enums\ColumnCategory;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\CommentOnTaskTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\TaskComment;

/**
 * Project boards over MCP: read by anyone who can open the project, changed
 * only by its contributors, always as the person who connected the client.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Engineering', 'key' => 'ENG']);
    addToProject($this->project, $this->member);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the login redirect']);
});

it('describes a project', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(GetProjectTool::class, ['project' => 'eng'])
        ->assertOk()
        ->assertSee(['ENG · Engineering', 'Columns on the board', "@{$this->member->username}"]);
});

it('lists what is on the board', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ListTasksTool::class, ['project' => 'ENG', 'scope' => 'all'])
        ->assertOk()
        ->assertSee("{$this->task->reference()} — Fix the login redirect");
});

it('filters tasks by assignee', function () {
    makeTask($this->project, $this->owner, ['title' => 'Mine', 'assignee_id' => $this->member->id]);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(ListTasksTool::class, ['project' => 'ENG', 'scope' => 'all', 'assignee' => "@{$this->member->username}"])
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Fix the login redirect');
});

it('opens a task with its comments', function () {
    TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->owner->id, 'body' => 'Repro on Safari only']);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => strtolower($this->task->reference())])
        ->assertOk()
        ->assertSee(['Fix the login redirect', 'Repro on Safari only']);
});

it('keeps guests away from projects', function () {
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    [$user] = connectAs($guest, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->task->reference()])
        ->assertHasErrors()
        ->assertDontSee('Fix the login redirect');
});

it('moves and reassigns a task', function () {
    $done = $this->project->columns()->where('category', ColumnCategory::Done)->firstOrFail();
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, [
            'task' => $this->task->reference(),
            'status' => $done->name,
            'assignee' => "@{$this->member->username}",
            'priority' => 'high',
        ])
        ->assertOk()
        ->assertSee('Updated');

    $task = $this->task->fresh();

    expect($task->board_column_id)->toBe($done->id)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->assignee_id)->toBe($this->member->id)
        ->and($task->priority->value)->toBe('high')
        // Untouched fields stay as they were.
        ->and($task->title)->toBe('Fix the login redirect');
});

it('will not assign a task to someone outside the project', function () {
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'assignee' => "@{$outsider->username}"])
        ->assertHasErrors();

    expect($this->task->fresh()->assignee_id)->toBeNull();
});

it('refuses changes from someone not on the project', function () {
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    [$user] = connectAs($outsider, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'title' => 'Hijacked'])
        ->assertHasErrors(['Join this project to work on its board.']);

    expect($this->task->fresh()->title)->toBe('Fix the login redirect');
});

it('comments as the person', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(CommentOnTaskTool::class, ['task' => $this->task->reference(), 'body' => 'Looking into it'])
        ->assertOk();

    expect(TaskComment::query()->sole())
        ->author_id->toBe($this->member->id)
        ->body->toBe('Looking into it');
});
