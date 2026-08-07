<?php

use App\Enums\ColumnCategory;
use App\Enums\TaskType;
use App\Events\TaskChanged;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Event;

it('numbers tasks per project and never reuses a number', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $first = fileTask($this, $workspace, $project, $owner, 'First');
    $second = fileTask($this, $workspace, $project, $owner, 'Second');

    expect($first['reference'])->toBe($project->key.'-1')
        ->and($second['reference'])->toBe($project->key.'-2');

    Task::whereKey($second['id'])->delete();

    // The counter is on the project, not derived from MAX(number): a reference
    // that used to mean one thing and now means another is worse than a gap.
    $third = fileTask($this, $workspace, $project, $owner, 'Third');

    expect($third['reference'])->toBe($project->key.'-3');
});

it('carries numbering on from where a lead says', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    // A team arriving from another tracker has references people already quoted.
    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'next_task_number' => 1499,
        ])
        ->assertRedirect();

    $task = fileTask($this, $workspace, $project, $owner, 'Carried over');

    expect($task['reference'])->toBe($project->key.'-1499')
        ->and($project->refresh()->next_task_number)->toBe(1500);
});

it('refuses to wind numbering back over a reference already issued', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    fileTask($this, $workspace, $project, $owner, 'First');
    fileTask($this, $workspace, $project, $owner, 'Second');

    // `(project_id, number)` is unique, so a counter behind the highest
    // reference does not fail here — it fails much later, on somebody's
    // perfectly ordinary click. Refused at the point it is set.
    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'next_task_number' => 2,
        ])
        ->assertSessionHasErrors('next_task_number');

    expect($project->refresh()->next_task_number)->toBe(3);

    // The number after the last one issued is the floor, not a number past it.
    $this->actingAs($owner)
        ->patch(route('projects.update', [$workspace->slug, $project->slug]), [
            'next_task_number' => 3,
        ])
        ->assertSessionHasNoErrors();
});

it('lands a new task in the first unstarted column and broadcasts it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $task = fileTask($this, $workspace, $project, $owner, 'Wire up the webhook');

    expect(Task::find($task['id'])->board_column_id)
        ->toBe(projectColumn($project, ColumnCategory::Unstarted)->id);

    Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event): bool => $event->action === 'created'
        && $event->taskId === $task['id']);
});

it('drops an assignee who is not on the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    $response = $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Somebody else’s problem',
            'type' => 'story',
            'priority' => 'medium',
            'assignee_id' => $outsider->id,
        ])
        ->assertCreated();

    // The whole feature turns on this: people are assigned work on the projects
    // they are on, and an id typed into a request cannot get around it.
    expect($response->json('task.assignee'))->toBeNull();

    addToProject($project, $outsider);

    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $response->json('task.id')]), [
            'assignee_id' => $outsider->id,
        ])
        ->assertOk()
        ->assertJsonPath('task.assignee.id', $outsider->id);
});

it('refuses a column from another project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner, ['name' => 'Ours']);
    $theirs = makeProject($workspace, $owner, ['name' => 'Theirs']);

    $task = fileTask($this, $workspace, $project, $owner, 'Stay put');
    $foreign = projectColumn($theirs, ColumnCategory::Done);

    $this->actingAs($owner)
        ->patchJson(route('tasks.move', [$workspace->slug, $task['id']]), [
            'board_column_id' => $foreign->id,
        ])
        ->assertOk();

    // The card kept its own board's column: a request naming another project's
    // column cannot move a card off its own board.
    expect(Task::find($task['id'])->board_column_id)->not->toBe($foreign->id);
});

it('marks a task complete when it reaches a done column, and clears it on the way back', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $task = fileTask($this, $workspace, $project, $owner, 'Ship it');
    $done = projectColumn($project, ColumnCategory::Done);
    $todo = projectColumn($project, ColumnCategory::Unstarted);

    $this->actingAs($owner)
        ->patchJson(route('tasks.move', [$workspace->slug, $task['id']]), [
            'board_column_id' => $done->id,
        ])
        ->assertOk()
        ->assertJsonPath('task.is_complete', true);

    // Dragged back out because it was closed too early: the date has to go with
    // it, or the board and every count disagree.
    $this->actingAs($owner)
        ->patchJson(route('tasks.move', [$workspace->slug, $task['id']]), [
            'board_column_id' => $todo->id,
        ])
        ->assertOk()
        ->assertJsonPath('task.is_complete', false);

    expect(Task::find($task['id'])->completed_at)->toBeNull();
});

it('places a dropped card between the two it was dropped between', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $first = fileTask($this, $workspace, $project, $owner, 'First');
    $second = fileTask($this, $workspace, $project, $owner, 'Second');
    $third = fileTask($this, $workspace, $project, $owner, 'Third');

    $this->actingAs($owner)
        ->patchJson(route('tasks.move', [$workspace->slug, $third['id']]), [
            'before_task_id' => $first['id'],
            'after_task_id' => $second['id'],
        ])
        ->assertOk();

    $order = Task::where('project_id', $project->id)->ordered()->pluck('title')->all();

    expect($order)->toBe(['First', 'Third', 'Second']);
});

it('rebalances a column whose gaps have run out rather than losing the order', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $column = projectColumn($project, ColumnCategory::Unstarted);

    // Adjacent positions: there is no integer left between them, which is the
    // one case the sparse scheme cannot answer without spreading the list.
    $first = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'position' => 100,
        'title' => 'First',
    ]);
    $second = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'position' => 101,
        'title' => 'Second',
    ]);
    $moving = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'position' => 500,
        'title' => 'Moving',
    ]);

    $this->actingAs($owner)
        ->patchJson(route('tasks.move', [$workspace->slug, $moving->id]), [
            'board_column_id' => $column->id,
            'before_task_id' => $first->id,
            'after_task_id' => $second->id,
        ])
        ->assertOk();

    expect(Task::where('project_id', $project->id)->ordered()->pluck('title')->all())
        ->toBe(['First', 'Moving', 'Second']);
});

it('keeps the hierarchy one level deep', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $epic = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
        'type' => TaskType::Epic,
        'title' => 'Payments',
    ]);

    $story = fileTask($this, $workspace, $project, $owner, 'Card form', [
        'parent_task_id' => $epic->id,
    ]);

    expect(Task::find($story['id'])->parent_task_id)->toBe($epic->id);

    // Promoted to an epic, it loses its parent — an epic inside an epic is the
    // second level the model does not have.
    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $story['id']]), [
            'type' => 'epic',
            'parent_task_id' => $epic->id,
        ])
        ->assertOk();

    expect(Task::find($story['id'])->parent_task_id)->toBeNull();
});

it('frees an epic’s children rather than deleting them with it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $column = projectColumn($project, ColumnCategory::Unstarted)->id;

    $epic = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column,
        'type' => TaskType::Epic,
        'reporter_id' => $owner->id,
    ]);

    $child = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column,
        'parent_task_id' => $epic->id,
    ]);

    $this->actingAs($owner)
        ->deleteJson(route('tasks.destroy', [$workspace->slug, $epic->id]))
        ->assertOk();

    expect(Task::find($child->id))->not->toBeNull()
        ->and(Task::find($child->id)->parent_task_id)->toBeNull();
});

it('lets the reporter delete their own task and refuses everyone else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $reporter = joinWorkspace($workspace);
    $other = joinWorkspace($workspace);
    addToProject($project, $reporter);
    addToProject($project, $other);

    $task = fileTask($this, $workspace, $project, $reporter, 'Mine');

    // Deleting is the one action that leaves no trace — a reference to it in a
    // channel becomes a dead link — so it is narrower than editing.
    $this->actingAs($other)
        ->deleteJson(route('tasks.destroy', [$workspace->slug, $task['id']]))
        ->assertForbidden();

    $this->actingAs($reporter)
        ->deleteJson(route('tasks.destroy', [$workspace->slug, $task['id']]))
        ->assertOk();
});

it('opens the card a reference in the URL names', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $task = fileTask($this, $workspace, $project, $owner, 'Follow the link');

    $this->actingAs($owner)
        ->get(route('projects.board', [$workspace->slug, $project->slug]).'?task='.$task['reference'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openTask.id', $task['id']));

    // A reference from another team's board resolves to nothing here rather
    // than to their card.
    $this->actingAs($owner)
        ->get(route('projects.board', [$workspace->slug, $project->slug]).'?task=OTHER-999')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openTask', null));
});

/**
 * File a task through the endpoint, returning the card the server answered with.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function fileTask(
    $test,
    $workspace,
    Project $project,
    $author,
    string $title,
    array $attributes = [],
): array {
    return $test->actingAs($author)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => $title,
            'type' => 'story',
            'priority' => 'medium',
            ...$attributes,
        ])
        ->assertCreated()
        ->json('task');
}
