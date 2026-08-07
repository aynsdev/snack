<?php

use App\Enums\ColumnCategory;
use App\Enums\ReleaseState;
use App\Models\Release;
use App\Models\Task;

it('plans a release with a window and notes', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $this->actingAs($owner)
        ->post(route('projects.releases.store', [$workspace->slug, $project->slug]), [
            'name' => '1.4.0',
            'notes' => 'Card payments, and the receipt bug.',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-21',
        ])
        ->assertRedirect();

    $release = $project->releases()->firstOrFail();

    expect($release->name)->toBe('1.4.0')
        ->and($release->notes)->toBe('Card payments, and the receipt bug.')
        ->and($release->starts_on?->toDateString())->toBe('2026-03-01')
        ->and($release->ends_on?->toDateString())->toBe('2026-03-21');
});

it('refuses a second release by the same name, and one that ends before it starts', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    Release::factory()->create(['project_id' => $project->id, 'name' => '1.4.0']);

    // A team quoting "1.4.0" must not have to ask which 1.4.0 — and the column
    // is citext, so the check is not case-sensitive either.
    $this->actingAs($owner)
        ->post(route('projects.releases.store', [$workspace->slug, $project->slug]), [
            'name' => '1.4.0',
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($owner)
        ->post(route('projects.releases.store', [$workspace->slug, $project->slug]), [
            'name' => '1.5.0',
            'starts_on' => '2026-03-21',
            'ends_on' => '2026-03-01',
        ])
        ->assertSessionHasErrors('ends_on');

    expect($project->releases()->count())->toBe(1);
});

it('lets another project use the same version number', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $ours = makeProject($workspace, $owner, ['name' => 'Ours']);
    $theirs = makeProject($workspace, $owner, ['name' => 'Theirs']);

    Release::factory()->create(['project_id' => $ours->id, 'name' => '1.0.0']);

    // Versions are the project's own vocabulary, like its labels and columns.
    $this->actingAs($owner)
        ->post(route('projects.releases.store', [$workspace->slug, $theirs->slug]), [
            'name' => '1.0.0',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($theirs->releases()->count())->toBe(1);
});

it('schedules a card into a release and takes it out again', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $release = Release::factory()->create(['project_id' => $project->id]);

    $task = $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Card payments',
            'type' => 'story',
            'priority' => 'medium',
        ])
        ->assertCreated()
        ->json('task');

    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $task['id']]), [
            'release_id' => $release->id,
        ])
        ->assertOk()
        ->assertJsonPath('task.release.name', $release->name);

    expect(Task::find($task['id'])->release_id)->toBe($release->id);

    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $task['id']]), [
            'release_id' => null,
        ])
        ->assertOk()
        ->assertJsonPath('task.release', null);

    expect(Task::find($task['id'])->release_id)->toBeNull();
});

it('drops a release belonging to another project rather than scheduling into it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner, ['name' => 'Ours']);
    $theirs = makeProject($workspace, $owner, ['name' => 'Theirs']);

    $release = Release::factory()->create(['project_id' => $theirs->id]);
    $task = $this->actingAs($owner)
        ->postJson(route('projects.tasks.store', [$workspace->slug, $project->slug]), [
            'title' => 'Ours to do',
            'type' => 'story',
            'priority' => 'medium',
        ])
        ->assertCreated()
        ->json('task');

    // Silently null rather than an error, the same as an assignee who is not on
    // the project: the id came from a picker, and a stale picker is a race.
    $this->actingAs($owner)
        ->patchJson(route('tasks.update', [$workspace->slug, $task['id']]), [
            'release_id' => $release->id,
        ])
        ->assertOk();

    expect(Task::find($task['id'])->release_id)->toBeNull();
});

it('unschedules the work when a release is cancelled, and keeps it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $release = Release::factory()->create(['project_id' => $project->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
        'release_id' => $release->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.releases.destroy', [$workspace->slug, $project->slug, $release->id]))
        ->assertRedirect();

    // Cancelling a release means "not like that", never "not at all".
    expect(Task::find($task->id))->not->toBeNull()
        ->and(Task::find($task->id)->release_id)->toBeNull();
});

it('lists releases with the counts the progress bar is drawn from', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $release = Release::factory()->create([
        'project_id' => $project->id,
        'name' => '2.0.0',
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
        'release_id' => $release->id,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Done)->id,
        'release_id' => $release->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('projects.releases.index', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/releases')
            ->has('releases', 1)
            ->where('releases.0.name', '2.0.0')
            ->where('releases.0.state', 'unreleased')
            ->where('releases.0.task_count', 2)
            ->where('releases.0.completed_count', 1)
            // Counts, not contents: a table of versions must not cost a
            // board-sized payload per row to draw a bar.
            ->missing('releases.0.tasks')
            ->where('canManage', true));
});

it('shows one release with the work going out in it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $release = Release::factory()->create(['project_id' => $project->id]);

    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
        'release_id' => $release->id,
    ]);

    $this->actingAs($owner)
        ->get(route('projects.releases.show', [$workspace->slug, $project->slug, $release->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects/release')
            ->where('release.name', $release->name)
            ->has('release.tasks', 2));
});

it('marks a release as gone out, and keeps the first date on a second click', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $release = Release::factory()->overdue()->create(['project_id' => $project->id]);

    expect($release->isOverdue())->toBeTrue();

    $this->actingAs($owner)
        ->post(route('projects.releases.released.store', [$workspace->slug, $project->slug, $release->id]))
        ->assertRedirect();

    $releasedAt = $release->refresh()->released_at;

    expect($release->state())->toBe(ReleaseState::Released)
        // A version that has gone out cannot be late, whatever its date said.
        ->and($release->isOverdue())->toBeFalse();

    // A double-submitted "Release" must not move the date already recorded.
    $this->actingAs($owner)
        ->post(route('projects.releases.released.store', [$workspace->slug, $project->slug, $release->id]))
        ->assertRedirect();

    expect($release->refresh()->released_at?->toIso8601String())
        ->toBe($releasedAt?->toIso8601String());

    // Marked shipped by mistake — correctable, or the row would be a lie.
    $this->actingAs($owner)
        ->delete(route('projects.releases.released.destroy', [$workspace->slug, $project->slug, $release->id]))
        ->assertRedirect();

    expect($release->refresh()->state())->toBe(ReleaseState::Unreleased)
        ->and($release->isOverdue())->toBeTrue();
});

it('lets a reader look but not plan', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $outsider = joinWorkspace($workspace);

    // Reading a project is open to the workspace; shaping its plan is a lead's.
    $this->actingAs($outsider)
        ->get(route('projects.releases.index', [$workspace->slug, $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));

    $this->actingAs($outsider)
        ->post(route('projects.releases.store', [$workspace->slug, $project->slug]), [
            'name' => '9.9.9',
        ])
        ->assertForbidden();

    expect($project->releases()->count())->toBe(0);
});

it('reads a release as overdue on its date rather than on its contents', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $release = Release::factory()->overdue()->create(['project_id' => $project->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Unstarted)->id,
        'release_id' => $release->id,
    ]);

    expect($release->isOverdue())->toBeTrue();

    // Finishing the work does not ship it. A version whose cards are all done
    // but which nobody has released is still late — how full it is, is the
    // progress bar's question, and it is asked separately.
    $task->forceFill(['completed_at' => now()])->save();

    expect($release->refresh()->isOverdue())->toBeTrue();

    $release->forceFill(['released_at' => now()])->save();

    expect($release->isOverdue())->toBeFalse();
});
