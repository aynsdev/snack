<?php

use App\Enums\ColumnCategory;
use App\Enums\WorkspaceRole;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskLink;
use App\Models\TaskRelation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner, ['uses_sprints' => false]);
    $this->task = makeTask($this->project, $this->owner);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
    $this->token = mobileToken($this->owner);
});

it('files a card on a project', function () {
    $this->withToken($this->token)
        ->postJson("{$this->base}/projects/{$this->project->slug}/tasks", [
            'title' => 'Login screen',
            'type' => 'story',
            'priority' => 'high',
        ])
        ->assertCreated()
        ->assertJsonPath('task.title', 'Login screen')
        ->assertJsonPath('task.priority', 'high');
});

it('refuses to file a card for a member not on the project', function () {
    $member = joinWorkspace($this->workspace);

    $this->withToken(mobileToken($member))
        ->postJson("{$this->base}/projects/{$this->project->slug}/tasks", [
            'title' => 'Sneaky', 'type' => 'task', 'priority' => 'low',
        ])
        ->assertForbidden();
});

it('opens, edits and deletes a card', function () {
    $this->withToken($this->token)
        ->getJson("{$this->base}/tasks/{$this->task->id}")
        ->assertOk()
        ->assertJsonPath('task.id', $this->task->id);

    $this->withToken($this->token)
        ->patchJson("{$this->base}/tasks/{$this->task->id}", ['title' => 'Renamed card'])
        ->assertOk()
        ->assertJsonPath('task.title', 'Renamed card');

    $this->withToken($this->token)
        ->deleteJson("{$this->base}/tasks/{$this->task->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Task::find($this->task->id))->toBeNull();
});

it('hides a card from someone outside the workspace', function () {
    $this->withToken(mobileToken(User::factory()->create()))
        ->getJson("{$this->base}/tasks/{$this->task->id}")
        ->assertNotFound();
});

it('hides a card from a guest who is not on its project', function () {
    $guest = joinWorkspace($this->workspace, role: WorkspaceRole::Guest);

    $this->withToken(mobileToken($guest))
        ->getJson("{$this->base}/tasks/{$this->task->id}")
        ->assertNotFound();
});

it('moves a card to another column', function () {
    $started = projectColumn($this->project, ColumnCategory::Started);

    $this->withToken($this->token)
        ->patchJson("{$this->base}/tasks/{$this->task->id}/position", ['board_column_id' => $started->id])
        ->assertOk()
        ->assertJsonPath('task.board_column_id', $started->id);
});

it('searches cards for the picker', function () {
    $other = makeTask($this->project, $this->owner, ['title' => 'Payment gateway']);

    $this->withToken($this->token)
        ->getJson("{$this->base}/tasks/{$this->task->id}/picker?q=Payment")
        ->assertOk()
        ->assertJsonPath('tasks.0.id', $other->id);
});

it('comments on a card, edits and deletes the comment', function () {
    $id = $this->withToken($this->token)
        ->postJson("{$this->base}/tasks/{$this->task->id}/comments", ['body' => 'Looks good'])
        ->assertCreated()
        ->assertJsonPath('comment.body', 'Looks good')
        ->json('comment.id');

    $this->withToken($this->token)
        ->patchJson("{$this->base}/tasks/{$this->task->id}/comments/{$id}", ['body' => 'Looks great'])
        ->assertOk()
        ->assertJsonPath('comment.body', 'Looks great');

    $this->withToken($this->token)
        ->deleteJson("{$this->base}/tasks/{$this->task->id}/comments/{$id}")
        ->assertOk();

    expect(TaskComment::find($id))->toBeNull();
});

it('adds and removes a web link', function () {
    $id = $this->withToken($this->token)
        ->postJson("{$this->base}/tasks/{$this->task->id}/links", ['url' => 'https://github.com/pedi/snack/pull/12', 'title' => 'PR'])
        ->assertCreated()
        ->json('link.id');

    $this->withToken($this->token)
        ->deleteJson("{$this->base}/tasks/{$this->task->id}/links/{$id}")
        ->assertOk();

    expect(TaskLink::find($id))->toBeNull();
});

it('relates two cards and removes the relation from the other card', function () {
    $other = makeTask($this->project, $this->owner);

    $this->withToken($this->token)
        ->postJson("{$this->base}/tasks/{$this->task->id}/relations", ['related_task_id' => $other->id, 'type' => 'blocks'])
        ->assertCreated()
        ->assertJsonPath('relations.0.task.id', $other->id);

    $relation = TaskRelation::query()->sole();

    $this->withToken($this->token)
        ->deleteJson("{$this->base}/tasks/{$other->id}/relations/{$relation->id}")
        ->assertOk()
        ->assertJsonCount(0, 'relations');
});

it('attaches a file to a card and removes it', function () {
    Storage::fake('local');

    $id = $this->withToken($this->token)
        ->post("{$this->base}/tasks/{$this->task->id}/attachments", [
            'file' => UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('attachment.id');

    $this->withToken($this->token)
        ->deleteJson("{$this->base}/tasks/{$this->task->id}/attachments/{$id}")
        ->assertOk();

    expect(MessageAttachment::find($id))->toBeNull();
});
