<?php

use App\Enums\ColumnCategory;
use App\Models\Sprint;
use App\Models\Task;

it('shows an empty board while no sprint is running', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
    ]);

    // The board answers "what are we working on now", and during planning the
    // honest answer is "nothing yet" — not "the whole backlog".
    $this->actingAs($owner)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks', 0)->where('sprint', null));
});

it('puts every task on the board for a kanban project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner, ['uses_sprints' => false]);

    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
    ]);

    $this->actingAs($owner)
        ->get(route('projects.board', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tasks', 2));

    // There is nothing to plan into without sprints, so the backlog is not a
    // second address for the same list.
    $this->actingAs($owner)
        ->get(route('projects.backlog', [$workspace->slug, $project->slug]))
        ->assertRedirect(route('projects.board', [$workspace->slug, $project->slug]));
});

it('runs one sprint at a time', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $first = Sprint::factory()->create(['project_id' => $project->id, 'name' => 'Sprint 1']);
    $second = Sprint::factory()->create(['project_id' => $project->id, 'name' => 'Sprint 2']);

    $this->actingAs($owner)
        ->post(route('projects.sprints.start', [$workspace->slug, $project->slug, $first->id]))
        ->assertRedirect();

    expect($first->refresh()->isActive())->toBeTrue();

    // A board showing the union of two sprints is a board nobody can plan
    // against — and the refusal is a sentence, not a constraint violation.
    $this->actingAs($owner)
        ->post(route('projects.sprints.start', [$workspace->slug, $project->slug, $second->id]))
        ->assertSessionHasErrors('sprint');

    expect($second->refresh()->isActive())->toBeFalse();
});

it('carries unfinished work forward with the status it reached', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $sprint = Sprint::factory()->active()->create(['project_id' => $project->id]);
    $next = Sprint::factory()->create(['project_id' => $project->id, 'name' => 'Sprint 2']);

    $inProgress = projectColumn($project, ColumnCategory::Started);
    $done = projectColumn($project, ColumnCategory::Done);

    $finished = Task::factory()->create([
        'project_id' => $project->id,
        'sprint_id' => $sprint->id,
        'board_column_id' => $done->id,
        'completed_at' => now(),
    ]);

    $unfinished = Task::factory()->create([
        'project_id' => $project->id,
        'sprint_id' => $sprint->id,
        'board_column_id' => $inProgress->id,
    ]);

    $this->actingAs($owner)
        ->post(route('projects.sprints.complete', [$workspace->slug, $project->slug, $sprint->id]), [
            'move_to_sprint_id' => $next->id,
        ])
        ->assertRedirect();

    expect($sprint->refresh()->isCompleted())->toBeTrue()
        // Finished work stays where it was: it is the record of what the team
        // did that fortnight.
        ->and($finished->refresh()->sprint_id)->toBe($sprint->id)
        ->and($unfinished->refresh()->sprint_id)->toBe($next->id)
        // The whole reason status and sprint are separate columns: something
        // that got as far as "In progress" is still there on Monday.
        ->and($unfinished->board_column_id)->toBe($inProgress->id)
        ->and($unfinished->completed_at)->toBeNull();
});

it('returns unfinished work to the backlog when there is no next sprint', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $sprint = Sprint::factory()->active()->create(['project_id' => $project->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'sprint_id' => $sprint->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Started)->id,
    ]);

    $this->actingAs($owner)
        ->post(route('projects.sprints.complete', [$workspace->slug, $project->slug, $sprint->id]))
        ->assertRedirect();

    expect($task->refresh()->sprint_id)->toBeNull();
});

it('refuses to complete a sprint that never started', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $sprint = Sprint::factory()->create(['project_id' => $project->id]);

    $this->actingAs($owner)
        ->post(route('projects.sprints.complete', [$workspace->slug, $project->slug, $sprint->id]))
        ->assertSessionHasErrors('sprint');
});

it('returns a deleted sprint’s work to the backlog and refuses to delete a running one', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $sprint = Sprint::factory()->create(['project_id' => $project->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'sprint_id' => $sprint->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
    ]);

    $running = Sprint::factory()->active()->create(['project_id' => $project->id]);

    $this->actingAs($owner)
        ->delete(route('projects.sprints.destroy', [$workspace->slug, $project->slug, $running->id]))
        ->assertSessionHasErrors('sprint');

    $this->actingAs($owner)
        ->delete(route('projects.sprints.destroy', [$workspace->slug, $project->slug, $sprint->id]))
        ->assertRedirect();

    expect(Sprint::find($sprint->id))->toBeNull()
        ->and($task->refresh()->sprint_id)->toBeNull();
});

it('refuses sprint control to anyone who is not a lead', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $member = joinWorkspace($workspace);
    addToProject($project, $member);

    $sprint = Sprint::factory()->create(['project_id' => $project->id]);

    // Starting one moves the whole team's board at once.
    $this->actingAs($member)
        ->post(route('projects.sprints.start', [$workspace->slug, $project->slug, $sprint->id]))
        ->assertForbidden();
});
