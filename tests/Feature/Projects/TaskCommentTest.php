<?php

use App\Enums\ProjectRole;
use App\Models\Task;
use App\Models\TaskComment;

it('records a comment and hands it back in the shape the panel draws', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $this->actingAs($owner)
        ->postJson(route('tasks.comments.store', [$workspace->slug, $task->id]), [
            'body' => '  The API returns 409 when the ride already started.  ',
        ])
        ->assertCreated()
        ->assertJsonPath('comment.body', 'The API returns 409 when the ride already started.')
        ->assertJsonPath('comment.author.id', $owner->id)
        ->assertJsonPath('comment.edited_at', null)
        ->assertJsonPath('comment.can.update', true);
});

it('sends a card’s discussion only when the card itself is asked for', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $owner->id,
        'body' => 'Older',
        'created_at' => now()->subHour(),
    ]);
    TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $owner->id,
        'body' => 'Newer',
    ]);

    $this->actingAs($owner)
        ->getJson(route('tasks.show', [$workspace->slug, $task->id]))
        ->assertOk()
        // Oldest first: a discussion is read in the order it happened.
        ->assertJsonPath('task.comments.0.body', 'Older')
        ->assertJsonPath('task.comments.1.body', 'Newer');

    // The board draws every card in the sprint and must not pay for any of it.
    $this->actingAs($owner)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('board.columns.0.tasks.0.comments'));
});

it('refuses a comment from somebody who is only watching the board', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);
    $onlooker = joinWorkspace($workspace);

    // Reading a board is open to the workspace; annotating it is contributing.
    $this->actingAs($onlooker)
        ->postJson(route('tasks.comments.store', [$workspace->slug, $task->id]), [
            'body' => 'Drive-by opinion',
        ])
        ->assertForbidden();
});

it('lets the author rewrite their own comment and marks it edited', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $comment = TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('tasks.comments.update', [$workspace->slug, $task->id, $comment->id]), [
            'body' => 'Corrected: it returns 422.',
        ])
        ->assertOk()
        ->assertJsonPath('comment.body', 'Corrected: it returns 422.');

    expect($comment->fresh()->edited_at)->not->toBeNull();
});

it('refuses to let anybody rewrite somebody else’s words', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);
    $lead = joinWorkspace($workspace);
    addToProject($project, $lead, ProjectRole::Lead);

    $comment = TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $owner->id,
        'body' => 'What I said',
    ]);

    // Not even a lead: an edit by a third party is the record ceasing to be
    // true while still carrying the original name.
    $this->actingAs($lead)
        ->patchJson(route('tasks.comments.update', [$workspace->slug, $task->id, $comment->id]), [
            'body' => 'What they wish I had said',
        ])
        ->assertForbidden();

    expect($comment->fresh()->body)->toBe('What I said');
});

it('lets the author or a lead delete a comment, and nobody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $author = joinWorkspace($workspace);
    addToProject($project, $author);
    $colleague = joinWorkspace($workspace);
    addToProject($project, $colleague);

    $comment = TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $author->id,
    ]);

    $this->actingAs($colleague)
        ->deleteJson(route('tasks.comments.destroy', [$workspace->slug, $task->id, $comment->id]))
        ->assertForbidden();

    // The owner leads the project they created — somebody has to be able to
    // clear up a remark left on the wrong card.
    $this->actingAs($owner)
        ->deleteJson(route('tasks.comments.destroy', [$workspace->slug, $task->id, $comment->id]))
        ->assertOk();

    expect(TaskComment::find($comment->id))->toBeNull();
});

it('404s a comment addressed through the wrong card', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $mine = makeTask($project, $owner);
    $other = makeTask($project, $owner);

    $comment = TaskComment::factory()->create([
        'task_id' => $mine->id,
        'author_id' => $owner->id,
    ]);

    // The router settles this through the scoped binding, before any controller
    // has a chance to forget the check.
    $this->actingAs($owner)
        ->patchJson(route('tasks.comments.update', [$workspace->slug, $other->id, $comment->id]), [
            'body' => 'Sneaking in',
        ])
        ->assertNotFound();
});

it('takes the discussion with the card when the card is deleted', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner, ['reporter_id' => $owner->id]);

    TaskComment::factory()->create(['task_id' => $task->id, 'author_id' => $owner->id]);

    $this->actingAs($owner)
        ->deleteJson(route('tasks.destroy', [$workspace->slug, $task->id]))
        ->assertOk();

    expect(Task::find($task->id))->toBeNull()
        ->and(TaskComment::query()->count())->toBe(0);
});

it('keeps a comment when the person who wrote it is deleted', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $author = joinWorkspace($workspace);
    addToProject($project, $author);

    $comment = TaskComment::factory()->create([
        'task_id' => $task->id,
        'author_id' => $author->id,
        'body' => 'We chose Postgres because of the citext columns.',
    ]);

    $author->delete();

    // The reasoning recorded on a card is what the next person needs; losing it
    // with the person who left is worse than showing it unattributed.
    expect($comment->fresh()->body)->toBe('We chose Postgres because of the citext columns.')
        ->and($comment->fresh()->author_id)->toBeNull();

    $this->actingAs($owner)
        ->getJson(route('tasks.show', [$workspace->slug, $task->id]))
        ->assertOk()
        ->assertJsonPath('task.comments.0.author', null);
});
