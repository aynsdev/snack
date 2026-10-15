<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\AddLinkTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\LinkTasksTool;
use App\Mcp\Tools\RemoveLinkTool;
use App\Mcp\Tools\UnlinkTasksTool;
use App\Models\TaskRelation;

/**
 * Links over MCP: web links such as pull requests, and task-to-task links,
 * added by the task's contributors and read back on both cards.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Pedicab Web App', 'key' => 'PWA']);
    addToProject($this->project, $this->member);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the fare rounding']);
    $this->other = makeTask($this->project, $this->owner, ['title' => 'Rewrite the fare engine']);
});

it('attaches a pull request and shows it on the task', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(AddLinkTool::class, ['task' => $this->task->reference(), 'url' => 'https://github.com/pedi/app/pull/42', 'title' => 'PR #42'])
        ->assertOk()
        ->assertSee("Linked {$this->task->reference()} to PR #42");

    // The same URL again retitles it rather than adding a second.
    SnackServer::actingAs($user)
        ->tool(AddLinkTool::class, ['task' => $this->task->reference(), 'url' => 'https://github.com/pedi/app/pull/42', 'title' => 'PR #42: Fix rounding'])
        ->assertOk();

    expect($this->task->links()->count())->toBe(1);

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->task->reference()])
        ->assertSee(['Links:', 'PR #42: Fix rounding — https://github.com/pedi/app/pull/42']);

    SnackServer::actingAs($user)
        ->tool(RemoveLinkTool::class, ['task' => $this->task->reference(), 'url' => 'https://github.com/pedi/app/pull/42'])
        ->assertOk();

    expect($this->task->links()->count())->toBe(0);
});

it('refuses a link that is not a web address', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(AddLinkTool::class, ['task' => $this->task->reference(), 'url' => 'javascript:alert(1)'])
        ->assertHasErrors(['http:// or https://']);

    expect($this->task->links()->count())->toBe(0);
});

it('links two tasks and words it from each side', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(LinkTasksTool::class, ['task' => $this->other->reference(), 'other' => $this->task->reference(), 'type' => 'blocks'])
        ->assertOk()
        ->assertSee("{$this->other->reference()} blocks {$this->task->reference()}");

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->task->reference()])
        ->assertSee("is blocked by {$this->other->reference()} — Rewrite the fare engine");

    SnackServer::actingAs($user)
        ->tool(GetTaskTool::class, ['task' => $this->other->reference()])
        ->assertSee("blocks {$this->task->reference()} — Fix the fare rounding");

    SnackServer::actingAs($user)
        ->tool(LinkTasksTool::class, ['task' => $this->task->reference(), 'other' => $this->other->reference(), 'type' => 'blocks'])
        ->assertHasErrors(['already linked']);

    SnackServer::actingAs($user)
        ->tool(UnlinkTasksTool::class, ['task' => $this->task->reference(), 'other' => $this->other->reference()])
        ->assertOk();

    expect(TaskRelation::query()->count())->toBe(0);
});

it('will not link a task to itself', function () {
    [$user] = connectAs($this->member, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(LinkTasksTool::class, ['task' => $this->task->reference(), 'other' => $this->task->reference(), 'type' => 'relates_to'])
        ->assertHasErrors(['cannot be linked to itself']);
});

it('refuses links from someone not on the project', function () {
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    [$user] = connectAs($outsider, $this->workspace);

    SnackServer::actingAs($user)
        ->tool(AddLinkTool::class, ['task' => $this->task->reference(), 'url' => 'https://example.com'])
        ->assertHasErrors(['Join this project to work on its board.']);
});
