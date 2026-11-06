<?php

use App\Ai\BiteContext;
use App\Ai\Tools\UpdateTask;
use App\Enums\ColumnCategory;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Laravel\Ai\Tools\Request;

/**
 * Bite changing a task that is already on a board, such as "assign this to
 * renz" under the task it just filed.
 *
 * Like filing one, it is a write other people see, so the card has to name the
 * change exactly, and the click is consent but not authority.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->renz = joinWorkspace($this->workspace, User::factory()->create(['name' => 'Renz Santos', 'username' => 'renz']));

    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Pedi Client', 'key' => 'PCA']);
    addToProject($this->project, $this->ada);
    addToProject($this->project, $this->renz);

    $this->task = makeTask($this->project, $this->ada, ['title' => 'Replace the legacy success dialog']);
});

function updateTaskAs(User $user): UpdateTask
{
    return new UpdateTask(new BiteContext(
        workspace: test()->workspace,
        user: $user,
        container: test()->channel,
    ));
}

it('assigns a task to a project member', function () {
    $result = (string) updateTaskAs($this->ada)->handle(new Request([
        'task' => $this->task->reference(),
        'assignee' => 'renz',
    ]));

    expect($result)->toContain('assignee → Renz Santos')
        ->and($this->task->fresh()->assignee_id)->toBe($this->renz->id);
});

it('changes only the fields it was given', function () {
    $this->task->update(['assignee_id' => $this->renz->id, 'description' => 'Keep me']);

    updateTaskAs($this->ada)->handle(new Request([
        'task' => strtolower($this->task->reference()),
        'priority' => 'high',
        'description' => null,
    ]));

    $task = $this->task->fresh();

    expect($task->priority->value)->toBe('high')
        ->and($task->assignee_id)->toBe($this->renz->id)
        ->and($task->description)->toBe('Keep me');
});

it('moves a task to another column by name', function () {
    $done = projectColumn($this->project, ColumnCategory::Done);

    $result = (string) updateTaskAs($this->ada)->handle(new Request([
        'task' => $this->task->reference(),
        'status' => $done->name,
    ]));

    expect($result)->toContain("status → {$done->name}")
        ->and($this->task->fresh()->board_column_id)->toBe($done->id);
});

it('unassigns with none', function () {
    $this->task->update(['assignee_id' => $this->renz->id]);

    updateTaskAs($this->ada)->handle(new Request([
        'task' => $this->task->reference(),
        'assignee' => 'none',
    ]));

    expect($this->task->fresh()->assignee_id)->toBeNull();
});

/**
 * SaveTask quietly drops an assignee who is not on the project, which is right
 * for a stale picker and wrong here: Bite would then say it assigned someone.
 */
it('changes nothing when the assignee is not on the project', function () {
    joinWorkspace($this->workspace, User::factory()->create(['username' => 'outsider']));

    $result = (string) updateTaskAs($this->ada)->handle(new Request([
        'task' => $this->task->reference(),
        'assignee' => 'outsider',
        'priority' => 'urgent',
    ]));

    $task = $this->task->fresh();

    expect($result)->toContain('Nothing was changed')
        ->and($task->assignee_id)->toBeNull()
        ->and($task->priority->value)->not->toBe('urgent');
});

it('refuses someone who cannot contribute to the project', function () {
    $stranger = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $result = (string) updateTaskAs($stranger)->handle(new Request([
        'task' => $this->task->reference(),
        'assignee' => 'renz',
    ]));

    expect($result)->toContain('cannot change')
        ->and($this->task->fresh()->assignee_id)->toBeNull();
});

it('cannot reach a task in another workspace', function () {
    [$other, $stranger] = makeWorkspaceWithOwner();
    $elsewhere = makeProject($other, $stranger, ['key' => 'ELS']);
    $theirs = makeTask($elsewhere, $stranger);

    $result = (string) updateTaskAs($this->ada)->handle(new Request([
        'task' => $theirs->reference(),
        'priority' => 'high',
    ]));

    expect($result)->toContain('no task with that reference')
        ->and($theirs->fresh()->priority->value)->not->toBe('high');
});

it('names the task and the resolved change in the approval card', function () {
    $approval = updateTaskAs($this->ada)->shouldRequestApproval(new Request([
        'task' => $this->task->reference(),
        'assignee' => '@renz',
    ]));

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toContain($this->task->reference())
        ->and($approval->reason)->toContain('Replace the legacy success dialog')
        ->and($approval->reason)->toContain('assignee → Renz Santos');
});
