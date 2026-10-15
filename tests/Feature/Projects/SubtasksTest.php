<?php

use App\Enums\ColumnCategory;
use App\Enums\TaskType;
use App\Events\TaskChanged;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\Task;
use Illuminate\Support\Facades\Event;

/**
 * Subtasks the way Jira has them: listed on the parent with their status,
 * counted on the parent's card, and filed into the parent's sprint and
 * release.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner, ['key' => 'PWA']);

    $this->sprint = Sprint::factory()->active()->create(['project_id' => $this->project->id]);
    $this->release = Release::factory()->create(['project_id' => $this->project->id]);

    $this->parent = makeTask($this->project, $this->owner, [
        'title' => 'Release v3.2.1',
        'sprint_id' => $this->sprint->id,
        'release_id' => $this->release->id,
    ]);
});

it('files a subtask into its parent’s sprint and release', function () {
    $this->actingAs($this->owner)
        ->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
            'title' => 'Bump the version',
            'type' => 'task',
            'priority' => 'medium',
            'parent_task_id' => $this->parent->id,
        ])
        ->assertCreated();

    expect(Task::query()->where('title', 'Bump the version')->sole())
        ->parent_task_id->toBe($this->parent->id)
        ->sprint_id->toBe($this->sprint->id)
        ->release_id->toBe($this->release->id);
});

it('still files a subtask into the backlog when asked to', function () {
    $this->actingAs($this->owner)
        ->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
            'title' => 'Later',
            'type' => 'task',
            'priority' => 'medium',
            'parent_task_id' => $this->parent->id,
            'sprint_id' => null,
        ])
        ->assertCreated();

    expect(Task::query()->where('title', 'Later')->sole()->sprint_id)->toBeNull();
});

it('lists subtasks on the opened parent, with progress', function () {
    $done = projectColumn($this->project, ColumnCategory::Done);
    makeTask($this->project, $this->owner, ['title' => 'Write notes', 'parent_task_id' => $this->parent->id]);
    makeTask($this->project, $this->owner, [
        'title' => 'Tag the build',
        'parent_task_id' => $this->parent->id,
        'board_column_id' => $done->id,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson(route('tasks.show', [$this->workspace->slug, $this->parent->id]))
        ->assertOk()
        ->assertJsonCount(2, 'task.subtasks')
        ->assertJsonPath('task.subtask_progress', ['done' => 1, 'total' => 2]);

    $tagged = collect($response->json('task.subtasks'))->firstWhere('title', 'Tag the build');

    expect($tagged)
        ->is_complete->toBeTrue()
        ->status->toBe($done->name);
});

it('counts subtasks on the board’s cards', function () {
    makeTask($this->project, $this->owner, ['parent_task_id' => $this->parent->id, 'sprint_id' => $this->sprint->id]);

    $this->actingAs($this->owner)
        ->get(route('projects.board', [$this->workspace->slug, $this->project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('id', $this->parent->id)['subtask_progress'] === ['done' => 0, 'total' => 1]));
});

it('gives a card with no subtasks no progress', function () {
    $this->actingAs($this->owner)
        ->getJson(route('tasks.show', [$this->workspace->slug, $this->parent->id]))
        ->assertOk()
        ->assertJsonPath('task.subtask_progress', null)
        ->assertJsonPath('task.subtasks', []);
});

it('files a subtask with no parent as a task', function () {
    $this->actingAs($this->owner)
        ->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
            'title' => 'Orphan', 'type' => 'subtask', 'priority' => 'medium',
        ])
        ->assertCreated()
        ->assertJsonPath('task.type', 'task');
});

it('never puts a subtask under an epic or another subtask', function () {
    $epic = makeTask($this->project, $this->owner, ['type' => TaskType::Epic]);
    $subtask = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);

    foreach ([$epic, $subtask] as $parent) {
        $this->actingAs($this->owner)
            ->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
                'title' => "Under {$parent->id}", 'type' => 'subtask', 'priority' => 'medium', 'parent_task_id' => $parent->id,
            ])
            ->assertCreated()
            ->assertJsonPath('task.type', 'task')
            ->assertJsonPath('task.epic', null);
    }
});

it('turns a subtask whose parent is removed into a task', function () {
    $subtask = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);

    $this->actingAs($this->owner)
        ->patchJson(route('tasks.update', [$this->workspace->slug, $subtask->id]), ['parent_task_id' => null])
        ->assertOk()
        ->assertJsonPath('task.type', 'task');
});

it('will not make a card with subtasks into a subtask', function () {
    makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);
    $grandparent = makeTask($this->project, $this->owner);
    $this->parent->update(['parent_task_id' => $grandparent->id]);

    $this->actingAs($this->owner)
        ->patchJson(route('tasks.update', [$this->workspace->slug, $this->parent->id]), ['type' => 'subtask'])
        ->assertOk()
        ->assertJsonPath('task.type', $this->parent->type->value);
});

it('carries unfinished subtasks along when the parent changes sprint', function () {
    $next = Sprint::factory()->create(['project_id' => $this->project->id, 'name' => 'Next']);
    $open = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id, 'sprint_id' => $this->sprint->id]);
    $finished = makeTask($this->project, $this->owner, [
        'type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id, 'sprint_id' => $this->sprint->id,
        'board_column_id' => projectColumn($this->project, ColumnCategory::Done)->id, 'completed_at' => now(),
    ]);
    $story = makeTask($this->project, $this->owner, ['type' => TaskType::Story, 'parent_task_id' => $this->parent->id, 'sprint_id' => $this->sprint->id]);

    $this->actingAs($this->owner)
        ->patchJson(route('tasks.move', [$this->workspace->slug, $this->parent->id]), ['sprint_id' => $next->id])
        ->assertOk();

    expect($open->fresh()->sprint_id)->toBe($next->id)
        ->and($finished->fresh()->sprint_id)->toBe($this->sprint->id)
        ->and($story->fresh()->sprint_id)->toBe($this->sprint->id);

    $this->actingAs($this->owner)
        ->patchJson(route('tasks.move', [$this->workspace->slug, $this->parent->id]), ['in_backlog' => true])
        ->assertOk();

    expect($open->fresh()->sprint_id)->toBeNull();
});

it('redraws the parent’s card when a subtask is finished', function () {
    $subtask = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);
    Event::fake([TaskChanged::class]);

    $this->actingAs($this->owner)
        ->patchJson(route('tasks.move', [$this->workspace->slug, $subtask->id]), [
            'board_column_id' => projectColumn($this->project, ColumnCategory::Done)->id,
        ])
        ->assertOk();

    Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event): bool => $event->taskId === $this->parent->id
        && $event->task?->getAttribute('completed_subtasks_count') === 1);
});

it('keeps a deleted parent’s subtasks as tasks', function () {
    $subtask = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);

    $this->actingAs($this->owner)
        ->deleteJson(route('tasks.destroy', [$this->workspace->slug, $this->parent->id]))
        ->assertOk();

    expect($subtask->fresh())
        ->type->toBe(TaskType::Task)
        ->parent_task_id->toBeNull();
});

it('offers a subtask only stories, tasks, and bugs as parents', function () {
    $subtask = makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id]);
    makeTask($this->project, $this->owner, ['type' => TaskType::Epic, 'title' => 'Candidate epic']);
    makeTask($this->project, $this->owner, ['type' => TaskType::Subtask, 'parent_task_id' => $this->parent->id, 'title' => 'Candidate subtask']);
    makeTask($this->project, $this->owner, ['type' => TaskType::Bug, 'title' => 'Candidate bug']);

    $titles = $this->actingAs($this->owner)
        ->getJson(route('tasks.picker', [$this->workspace->slug, $subtask->id, 'q' => 'candidate', 'purpose' => 'parent']))
        ->assertOk()
        ->json('tasks.*.title');

    expect($titles)->toBe(['Candidate bug']);
});
