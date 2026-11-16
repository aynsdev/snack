<?php

use App\Enums\OutboundEventType;
use App\Events\TaskChanged;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent']);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->dev);
    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['key' => 'PCA']);
    addToProject($this->project, $this->dev);

    $this->sprint = Sprint::factory()->active()->create(['project_id' => $this->project->id]);
    $this->tasks = collect(range(1, 3))->map(fn () => makeTask($this->project, $this->owner));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function bulkUpdate(object $test, User $actor, array $attributes): TestResponse
{
    return $test->actingAs($actor)->patchJson(
        route('projects.tasks.bulk', [$test->workspace->slug, $test->project->slug]),
        $attributes,
    );
}

/**
 * @return array<int, int>
 */
function taskIds(iterable $tasks): array
{
    return collect($tasks)->map(fn (Task $task): int => $task->id)->all();
}

it('moves every selected backlog card into a sprint', function () {
    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks), 'sprint_id' => $this->sprint->id])
        ->assertOk()
        ->assertJsonCount(3, 'tasks')
        ->assertJsonPath('tasks.0.sprint_id', $this->sprint->id)
        ->assertJsonPath('skipped', []);

    expect(Task::query()->whereKey(taskIds($this->tasks))->pluck('sprint_id')->unique()->all())->toBe([$this->sprint->id]);

    Event::assertDispatchedTimes(TaskChanged::class, 3);
});

it('sends every selected card back to the backlog, keeping its status', function () {
    $started = makeTask($this->project, $this->owner, ['sprint_id' => $this->sprint->id]);
    $columnId = $started->board_column_id;

    bulkUpdate($this, $this->dev, ['task_ids' => [$started->id], 'in_backlog' => true])->assertOk();

    expect($started->fresh()->sprint_id)->toBeNull()
        ->and($started->fresh()->board_column_id)->toBe($columnId);
});

it('takes unfinished subtasks along with their parent', function () {
    $parent = $this->tasks->first();
    $subtask = makeTask($this->project, $this->owner, ['type' => 'subtask', 'parent_task_id' => $parent->id]);

    bulkUpdate($this, $this->dev, ['task_ids' => [$parent->id], 'sprint_id' => $this->sprint->id])->assertOk();

    expect($subtask->fresh()->sprint_id)->toBe($this->sprint->id);
});

it('refuses a sprint that is finished, and changes nothing', function () {
    $finished = Sprint::factory()->completed()->create(['project_id' => $this->project->id]);

    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks), 'sprint_id' => $finished->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sprint_id');

    expect(Task::query()->whereKey(taskIds($this->tasks))->whereNotNull('sprint_id')->exists())->toBeFalse();
});

it('refuses a card from another project, and changes nothing', function () {
    $elsewhere = makeTask(makeProject($this->workspace, $this->owner), $this->owner);

    bulkUpdate($this, $this->dev, [
        'task_ids' => [...taskIds($this->tasks), $elsewhere->id],
        'sprint_id' => $this->sprint->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('task_ids');

    expect(Task::query()->whereKey(taskIds($this->tasks))->whereNotNull('sprint_id')->exists())->toBeFalse()
        ->and($elsewhere->fresh()->sprint_id)->toBeNull();
});

it('refuses somebody who is not on the project', function () {
    $outsider = joinWorkspace($this->workspace);

    bulkUpdate($this, $outsider, ['task_ids' => taskIds($this->tasks), 'sprint_id' => $this->sprint->id])
        ->assertForbidden();

    expect(Task::query()->whereKey(taskIds($this->tasks))->whereNotNull('sprint_id')->exists())->toBeFalse();
});

it('refuses a request that changes nothing', function () {
    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task_ids');
});

it('refuses more cards than one request may carry', function () {
    bulkUpdate($this, $this->dev, ['task_ids' => range(1, 51), 'sprint_id' => $this->sprint->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task_ids');
});

it('starts your own agent on every selected card when you assign yourself', function () {
    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks), 'agent' => 'assign', 'assignee_id' => $this->dev->id])
        ->assertOk();

    foreach ($this->tasks as $task) {
        expect($task->fresh()->assignee_id)->toBe($this->dev->id)
            ->and($task->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT]);
    }

    // The dispatcher hears about each card exactly as it would from the panel.
    $events = outboundEvents(OutboundEventType::TaskEligibilityChanged);

    expect($events)->toHaveCount(3)
        ->and($events->pluck('payload.actor.via')->unique()->all())->toBe(['web'])
        ->and($events->pluck('payload.data.after.label_names')->unique()->values()->all())->toBe([[TaskLabel::AGENT]]);
});

it('only requests the agent when the cards go to somebody else', function () {
    bulkUpdate($this, $this->owner, ['task_ids' => taskIds($this->tasks), 'agent' => 'assign', 'assignee_id' => $this->dev->id])
        ->assertOk();

    foreach ($this->tasks as $task) {
        expect($task->fresh()->assignee_id)->toBe($this->dev->id)
            ->and($task->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT_REQUESTED]);
    }
});

it('defaults the agent to the person asking', function () {
    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks), 'agent' => 'assign'])->assertOk();

    expect(Task::query()->whereKey(taskIds($this->tasks))->pluck('assignee_id')->unique()->all())->toBe([$this->dev->id]);
});

it('leaves a card that already has an agent alone, and says so', function () {
    [$first, $second] = $this->tasks;

    bulkUpdate($this, $this->owner, ['task_ids' => [$first->id], 'agent' => 'assign', 'assignee_id' => $this->owner->id])
        ->assertOk();

    bulkUpdate($this, $this->dev, ['task_ids' => [$first->id, $second->id], 'agent' => 'assign', 'assignee_id' => $this->dev->id])
        ->assertOk()
        ->assertJsonPath('skipped', [$first->reference()]);

    expect($first->fresh()->assignee_id)->toBe($this->owner->id)
        ->and($first->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT])
        ->and($second->fresh()->assignee_id)->toBe($this->dev->id);
});

it('keeps the other labels when handing cards to an agent', function () {
    $label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'bug']);
    $task = $this->tasks->first();
    $task->labels()->attach($label);

    bulkUpdate($this, $this->dev, ['task_ids' => [$task->id], 'agent' => 'assign'])->assertOk();

    expect($task->fresh()->labels->pluck('name')->sort()->values()->all())->toBe(['agent', 'bug']);
});

it('puts the cards in the sprint before handing them to the agent', function () {
    bulkUpdate($this, $this->dev, [
        'task_ids' => taskIds($this->tasks),
        'sprint_id' => $this->sprint->id,
        'agent' => 'assign',
    ])->assertOk();

    foreach ($this->tasks as $task) {
        expect($task->fresh()->sprint_id)->toBe($this->sprint->id)
            ->and($task->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT]);
    }
});

it('refuses an agent when the project has none, and changes nothing', function () {
    $project = makeProject($this->workspace, $this->owner);
    $task = makeTask($project, $this->owner);

    $this->actingAs($this->owner)
        ->patchJson(route('projects.tasks.bulk', [$this->workspace->slug, $project->slug]), [
            'task_ids' => [$task->id],
            'agent' => 'assign',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agent');

    expect($task->fresh()->assignee_id)->toBeNull();
});

it('refuses an assignee who is not on the project, and changes nothing', function () {
    $outsider = joinWorkspace($this->workspace);

    bulkUpdate($this, $this->dev, ['task_ids' => taskIds($this->tasks), 'agent' => 'assign', 'assignee_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assignee_id');

    expect(Task::query()->whereKey(taskIds($this->tasks))->whereNotNull('assignee_id')->exists())->toBeFalse();
});
