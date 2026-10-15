<?php

use App\Enums\TaskRelationType;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Models\TaskLink;
use App\Models\TaskRelation;

/**
 * The task panel's links, and the picker it chooses parents and linked
 * tasks with.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner, ['key' => 'PWA']);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the fare rounding']);
    $this->other = makeTask($this->project, $this->owner, ['title' => 'Rewrite the fare engine']);
});

it('adds and removes a web link', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('tasks.links.store', [$this->workspace->slug, $this->task->id]), [
            'url' => 'https://github.com/pedi/app/pull/7',
        ])
        ->assertCreated()
        ->assertJsonPath('link.label', 'github.com/pedi/app/pull/7');

    $this->actingAs($this->owner)
        ->deleteJson(route('tasks.links.destroy', [$this->workspace->slug, $this->task->id, $response->json('link.id')]))
        ->assertOk();

    expect(TaskLink::query()->count())->toBe(0);
});

it('rejects a link that is not a web address', function () {
    $this->actingAs($this->owner)
        ->postJson(route('tasks.links.store', [$this->workspace->slug, $this->task->id]), ['url' => 'ftp://files'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('sends links and relations with the opened card', function () {
    TaskLink::factory()->create(['task_id' => $this->task->id, 'url' => 'https://example.com/pr/1', 'title' => 'PR 1']);
    TaskRelation::factory()->create(['task_id' => $this->other->id, 'related_task_id' => $this->task->id, 'type' => TaskRelationType::Blocks]);

    $this->actingAs($this->owner)
        ->getJson(route('tasks.show', [$this->workspace->slug, $this->task->id]))
        ->assertOk()
        ->assertJsonPath('task.links.0.label', 'PR 1')
        ->assertJsonPath('task.relations.0.wording', 'is blocked by')
        ->assertJsonPath('task.relations.0.task.reference', $this->other->reference());
});

it('relates two cards and removes the relation from either side', function () {
    $this->actingAs($this->owner)
        ->postJson(route('tasks.relations.store', [$this->workspace->slug, $this->task->id]), [
            'related_task_id' => $this->other->id,
            'type' => 'duplicates',
        ])
        ->assertCreated()
        ->assertJsonPath('relations.0.wording', 'duplicates');

    $relation = TaskRelation::query()->sole();

    $this->actingAs($this->owner)
        ->deleteJson(route('tasks.relations.destroy', [$this->workspace->slug, $this->other->id, $relation->id]))
        ->assertOk()
        ->assertJsonPath('relations', []);
});

it('hides a related card the viewer cannot open', function () {
    // A guest sees only the projects they are on.
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    addToProject($this->project, $guest);
    $elsewhere = makeProject($this->workspace, $this->owner, ['key' => 'SEC']);
    $secret = makeTask($elsewhere, $this->owner, ['title' => 'Secret thing']);
    TaskRelation::factory()->create(['task_id' => $this->task->id, 'related_task_id' => $secret->id]);

    $this->actingAs($guest)
        ->getJson(route('tasks.show', [$this->workspace->slug, $this->task->id]))
        ->assertOk()
        ->assertJsonPath('task.relations', [])
        ->assertDontSee('Secret thing');
});

it('refuses to relate to a card the person cannot see', function () {
    $otherWorkspace = makeWorkspaceWithOwner();
    $foreign = makeTask(makeProject($otherWorkspace[0], $otherWorkspace[1]));

    $this->actingAs($this->owner)
        ->postJson(route('tasks.relations.store', [$this->workspace->slug, $this->task->id]), [
            'related_task_id' => $foreign->id,
            'type' => 'blocks',
        ])
        ->assertNotFound();
});

it('offers parents without the card itself or its subtasks', function () {
    $child = makeTask($this->project, $this->owner, ['title' => 'Fare child', 'parent_task_id' => $this->task->id]);
    $grandchild = makeTask($this->project, $this->owner, ['title' => 'Fare grandchild', 'parent_task_id' => $child->id]);

    $references = $this->actingAs($this->owner)
        ->getJson(route('tasks.picker', [$this->workspace->slug, $this->task->id, 'q' => 'fare', 'purpose' => 'parent']))
        ->assertOk()
        ->json('tasks.*.reference');

    expect($references)->toBe([$this->other->reference()]);
});

it('finds a card by its number', function () {
    $this->actingAs($this->owner)
        ->getJson(route('tasks.picker', [$this->workspace->slug, $this->task->id, 'q' => $this->other->reference()]))
        ->assertOk()
        ->assertJsonPath('tasks.0.id', $this->other->id);
});

it('offers an epic no parents', function () {
    $epic = makeTask($this->project, $this->owner, ['type' => TaskType::Epic]);

    $this->actingAs($this->owner)
        ->getJson(route('tasks.picker', [$this->workspace->slug, $epic->id, 'purpose' => 'parent']))
        ->assertOk()
        ->assertJsonPath('tasks', []);
});
