<?php

use App\Enums\ColumnCategory;
use App\Models\BoardColumn;
use App\Models\Task;
use App\Models\TaskLabel;

it('adds a column at the end of the board', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->post(route('projects.columns.store', [$workspace->slug, $project->slug]), [
            'name' => 'To test',
            'category' => 'started',
            'wip_limit' => 3,
        ])
        ->assertRedirect();

    $names = $project->columns()->ordered()->pluck('name')->all();

    expect($names)->toBe(['To do', 'In progress', 'Done', 'To test'])
        ->and($project->columns()->firstWhere('name', 'To test')->wip_limit)->toBe(3);
});

it('moves a removed column’s tasks instead of deleting them with it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $extra = BoardColumn::factory()->started()->create([
        'project_id' => $project->id,
        'name' => 'To fix',
        'position' => 999_999,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $extra->id,
    ]);

    $destination = projectColumn($project, ColumnCategory::Unstarted);

    $this->actingAs($owner)
        ->delete(route('projects.columns.destroy', [$workspace->slug, $project->slug, $extra->id]), [
            'move_to_column_id' => $destination->id,
        ])
        ->assertRedirect();

    // "Our QA step went away and took eleven tickets with it" is unrecoverable.
    expect(BoardColumn::find($extra->id))->toBeNull()
        ->and($task->refresh()->board_column_id)->toBe($destination->id);
});

it('keeps at least one column of each load-bearing category', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $done = projectColumn($project, ColumnCategory::Done);

    // Without a Done column a sprint would carry everything forward for ever,
    // and without an unstarted one new work would land wherever happened to be
    // first.
    $this->actingAs($owner)
        ->delete(route('projects.columns.destroy', [$workspace->slug, $project->slug, $done->id]))
        ->assertSessionHasErrors('column');

    $this->actingAs($owner)
        ->patch(route('projects.columns.update', [$workspace->slug, $project->slug, $done->id]), [
            'category' => 'started',
        ])
        ->assertSessionHasErrors('category');

    expect($project->columns()->where('category', 'done')->count())->toBe(1);
});

it('adds a column between the neighbours the board names', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $columns = $project->columns()->ordered()->get();
    [$inProgress, $done] = [$columns[1], $columns[2]];

    // The board's own add button sits left of Done and says so, because a
    // status added mid-flow is another step before finished — a column landing
    // to the right of Done is a board that reads backwards.
    $this->actingAs($owner)
        ->post(route('projects.columns.store', [$workspace->slug, $project->slug]), [
            'name' => 'To test',
            'category' => 'started',
            'before_column_id' => $inProgress->id,
            'after_column_id' => $done->id,
        ])
        ->assertRedirect();

    expect($project->columns()->ordered()->pluck('name')->all())
        ->toBe(['To do', 'In progress', 'To test', 'Done']);
});

it('still appends a column nobody placed', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    // The settings screen names no neighbours, and the end of the board is
    // where a column added from a list of columns belongs.
    $this->actingAs($owner)
        ->post(route('projects.columns.store', [$workspace->slug, $project->slug]), [
            'name' => 'Archived',
            'category' => 'done',
        ])
        ->assertRedirect();

    expect($project->columns()->ordered()->pluck('name')->all())
        ->toBe(['To do', 'In progress', 'Done', 'Archived']);
});

it('reorders a column by naming its new neighbours', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $columns = $project->columns()->ordered()->get();
    [$todo, $inProgress, $done] = [$columns[0], $columns[1], $columns[2]];

    $this->actingAs($owner)
        ->patch(route('projects.columns.update', [$workspace->slug, $project->slug, $done->id]), [
            'before_column_id' => $todo->id,
            'after_column_id' => $inProgress->id,
        ])
        ->assertRedirect();

    expect($project->columns()->ordered()->pluck('name')->all())
        ->toBe(['To do', 'Done', 'In progress']);
});

it('keeps labels to the project that defined them', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner, ['name' => 'Ours']);
    $theirs = makeProject($workspace, $owner, ['name' => 'Theirs']);

    $this->actingAs($owner)
        ->post(route('projects.labels.store', [$workspace->slug, $project->slug]), [
            'name' => 'needs design',
            'color' => 'rose',
        ])
        ->assertRedirect();

    $ours = $project->labels()->firstOrFail();
    $foreign = TaskLabel::factory()->create(['project_id' => $theirs->id]);

    $task = $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Labelled',
            'type' => 'story',
            'priority' => 'medium',
            'label_ids' => [$ours->id, $foreign->id],
        ])
        ->assertCreated()
        ->json('task');

    // The other project's label simply does not come back — it is a predicate
    // on the query rather than a check after the fact.
    expect($task['labels'])->toHaveCount(1)
        ->and($task['labels'][0]['name'])->toBe('needs design');
});

it('refuses two labels with the same name in one project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $payload = ['name' => 'tech debt', 'color' => 'slate'];

    $this->actingAs($owner)
        ->post(route('projects.labels.store', [$workspace->slug, $project->slug]), $payload)
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('projects.labels.store', [$workspace->slug, $project->slug]), $payload)
        ->assertSessionHasErrors('name');
});

it('refuses a member the right to change the board’s shape', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $member = joinWorkspace($workspace);
    addToProject($project, $member);

    // A column is not a piece of work — it is the process everybody's work
    // moves through.
    $this->actingAs($member)
        ->post(route('projects.columns.store', [$workspace->slug, $project->slug]), [
            'name' => 'Mine',
            'category' => 'started',
        ])
        ->assertForbidden();
});
