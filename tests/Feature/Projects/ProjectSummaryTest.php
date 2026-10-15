<?php

use App\Enums\ColumnCategory;
use App\Enums\TaskType;
use App\Models\Task;

it('counts the project by status, priority and type', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $todo = projectColumn($project, ColumnCategory::Unstarted);
    $done = projectColumn($project, ColumnCategory::Done);

    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'board_column_id' => $todo->id,
        'priority' => 'high',
        'type' => 'bug',
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $done->id,
        'priority' => 'low',
        'type' => 'story',
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('projects.summary', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/summary')

            // Every column, in board order, including the empty one — an empty
            // status is a fact about the project, not something to hide.
            ->has('statuses', 3)
            ->where('statuses.0.name', $todo->name)
            ->where('statuses.0.count', 2)
            ->where('statuses.1.count', 0)
            ->where('statuses.2.count', 1)

            // Every level of each enum, so the chart's shape does not change
            // with which happen to be in use.
            ->has('priorities', 5)
            ->where('priorities.1.value', 'high')
            ->where('priorities.1.count', 2)
            ->has('types', count(TaskType::cases())));
});

it('counts only the last seven days in its tiles', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $column = projectColumn($project, ColumnCategory::Unstarted);

    $recent = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'completed_at' => now()->subDay(),
    ]);

    $old = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'completed_at' => now()->subMonth(),
    ]);

    /*
     * Written through the query builder, and all three stamps in one statement:
     * an Eloquent `update()` sets `updated_at` to now for free, which is the
     * column under test.
     */
    Task::whereKey($recent->id)->toBase()->update([
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subDay(),
    ]);

    Task::whereKey($old->id)->toBase()->update([
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subMonth(),
    ]);

    $this->actingAs($owner)
        ->get(route('projects.summary', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recent.completed', 1)
            ->where('recent.updated', 1)
            // A count with no window is a number that only ever goes up.
            ->where('recent.created', 0));
});

it('counts work that is due soon and not yet finished', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $column = projectColumn($project, ColumnCategory::Unstarted);

    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'due_on' => now()->addDays(2),
    ]);

    // Already late still counts — it is not less urgent for being overdue.
    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'due_on' => now()->subDays(2),
    ]);

    // Finished, so not something to worry about.
    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'due_on' => now()->addDay(),
        'completed_at' => now(),
    ]);

    // Far off.
    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'due_on' => now()->addMonth(),
    ]);

    $this->actingAs($owner)
        ->get(route('projects.summary', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('recent.due_soon', 2));
});

it('lists the cards touched most recently, newest first', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $column = projectColumn($project, ColumnCategory::Unstarted);

    $older = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'title' => 'Filed a while ago',
    ]);

    $newer = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => $column->id,
        'title' => 'Touched just now',
    ]);

    Task::whereKey($older->id)->toBase()->update(['updated_at' => now()->subDays(3)]);

    $this->actingAs($owner)
        ->get(route('projects.summary', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activity', 2)
            ->where('activity.0.title', 'Touched just now')
            ->where('activity.0.status', $column->name));
});

it('is readable by anyone who can see the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    // A summary is a way of reading a board, and reading one is open to the
    // workspace — the same rule ProjectPolicy::view makes everywhere else.
    $this->actingAs($outsider)
        ->get(route('projects.summary', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));
});
