<?php

use App\Enums\NotificationReason;
use App\Models\Notification;
use App\Models\Task;

it('tells somebody a task was put in their name', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $assignee = joinWorkspace($workspace);
    addToProject($project, $assignee);

    $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Wire up the webhook',
            'type' => 'story',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
        ])
        ->assertCreated();

    $notification = Notification::query()
        ->forWorkspace($workspace, $assignee)
        ->firstOrFail();

    expect($notification->type)->toBe(NotificationReason::TaskAssigned->value)
        // The row carries where to go: an assignment has no container to link
        // through, so it builds its own link to the board.
        ->and(data_get($notification->data, 'task.project_slug'))->toBe($project->slug)
        ->and(data_get($notification->data, 'task.reference'))->toBe($project->key.'-1')
        ->and(data_get($notification->data, 'actor.id'))->toBe($owner->id);
});

it('says nothing when somebody picks up work themselves', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Mine',
            'type' => 'story',
            'priority' => 'medium',
            'assignee_id' => $owner->id,
        ])
        ->assertCreated();

    expect(Notification::query()->forWorkspace($workspace, $owner)->count())->toBe(0);
});

it('does not re-announce an assignment that has not moved', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $assignee = joinWorkspace($workspace);
    addToProject($project, $assignee);

    $task = $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Wire up the webhook',
            'type' => 'story',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
        ])
        ->assertCreated()
        ->json('task');

    // An unrelated edit to the same card: the assignee is where it was, so
    // there is nothing new to tell them.
    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $task['id']]), [
            'priority' => 'high',
            'assignee_id' => $assignee->id,
        ])
        ->assertOk();

    expect(Notification::query()->forWorkspace($workspace, $assignee)->count())->toBe(1);
});

it('says nothing when the named assignee is not on the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Not theirs',
            'type' => 'story',
            'priority' => 'medium',
            'assignee_id' => $outsider->id,
        ])
        ->assertCreated();

    // The assignment was dropped, so there is nothing to announce — and a
    // notification about work somebody does not have would be worse than none.
    expect(Task::firstWhere('title', 'Not theirs')->assignee_id)->toBeNull()
        ->and(Notification::query()->forWorkspace($workspace, $outsider)->count())->toBe(0);
});
