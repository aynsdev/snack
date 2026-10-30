<?php

use App\Enums\ColumnCategory;
use App\Enums\OutboundEventType;
use App\Enums\ProjectRole;
use App\Models\OutboundEvent;
use App\Models\Task;
use App\Models\TaskLabel;
use Illuminate\Support\Facades\Queue;

/**
 * `task.eligibility_changed`: told when, and only when, a task's assignee or
 * label set changes on a project whose agents are switched on.
 *
 * The queue is faked so each recorded row stays in the outbox to be read back;
 * delivery itself is covered in OutboundDeliveryTest.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent']);
    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    addToProject($this->project, $this->dev);

    $this->agentLabel = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);
    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the login', 'description' => 'It times out.']);

    $this->actingAs($this->owner);
});

/**
 * @return array<string, mixed>
 */
function eligibilityData(?OutboundEvent $event = null): array
{
    $event ??= outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole();

    return $event->payload['data'];
}

function updateTask(object $test, Task $task, array $attributes): void
{
    $test->patchJson(route('tasks.update', [$test->workspace->slug, $task->id]), $attributes)->assertOk();
}

it('reports an assignment with the task and project as they now stand', function () {
    updateTask($this, $this->task, ['assignee_id' => $this->dev->id]);

    $data = eligibilityData();
    $todo = projectColumn($this->project, ColumnCategory::Unstarted);
    $reference = "PCA-{$this->task->number}";

    expect($data['reason'])->toBe('updated')
        ->and($data['task_id'])->toBe($this->task->id)
        ->and($data['reference'])->toBe($reference)
        ->and($data['before'])->toEqual(['assignee_id' => null, 'label_ids' => [], 'label_names' => [], 'assignee_can_view_agent_channel' => false])
        ->and($data['after'])->toEqual(['assignee_id' => $this->dev->id, 'label_ids' => [], 'label_names' => [], 'assignee_can_view_agent_channel' => true])
        ->and($data['task'])->toEqual([
            'id' => $this->task->id,
            'reference' => $reference,
            'title' => 'Fix the login',
            'description' => 'It times out.',
            'type' => $this->task->type->value,
            'priority' => $this->task->priority->value,
            'column_id' => $todo->id,
            'column_name' => $todo->name,
            'assignee_id' => $this->dev->id,
            'reporter_id' => $this->owner->id,
            'label_ids' => [],
            'label_names' => [],
            'url' => rtrim(config('app.url'), '/')."/w/{$this->workspace->slug}/projects/{$this->project->slug}?task={$reference}",
        ])
        ->and($data['project'])->toEqual([
            'id' => $this->project->id,
            'key' => 'PCA',
            'name' => 'Pedi Client App',
            'lead_user_ids' => [$this->owner->id],
            'archived' => false,
            'agent' => [
                'channel_id' => $this->channel->id,
                'channel_name' => 'pca-agent',
                'in_progress_column_id' => null,
                'in_progress_column_name' => null,
                'review_column_id' => null,
                'review_column_name' => null,
            ],
        ]);
});

it('reports an unassignment', function () {
    $this->task->forceFill(['assignee_id' => $this->dev->id])->save();

    updateTask($this, $this->task, ['assignee_id' => null]);

    expect(eligibilityData()['before']['assignee_id'])->toBe($this->dev->id)
        ->and(eligibilityData()['after']['assignee_id'])->toBeNull();
});

it('reports a label being added and taken off', function () {
    $other = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'backend']);

    updateTask($this, $this->task, ['label_ids' => [$other->id, $this->agentLabel->id]]);
    updateTask($this, $this->task, ['label_ids' => [$other->id]]);

    [$added, $removed] = outboundEvents(OutboundEventType::TaskEligibilityChanged)->all();

    $both = collect([$this->agentLabel, $other])->sortBy('id')->values();

    expect(eligibilityData($added)['after'])->toEqual([
        'assignee_id' => null,
        'label_ids' => $both->pluck('id')->all(),
        'label_names' => $both->pluck('name')->all(),
        'assignee_can_view_agent_channel' => false,
    ])
        ->and(eligibilityData($removed)['before']['label_ids'])->toEqual($both->pluck('id')->all())
        ->and(eligibilityData($removed)['after'])->toEqual([
            'assignee_id' => null,
            'label_ids' => [$other->id],
            'label_names' => ['backend'],
            'assignee_can_view_agent_channel' => false,
        ]);
});

it('reports a task filed already assigned and labelled', function () {
    $response = $this->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
        'title' => 'Add dark mode',
        'type' => 'task',
        'priority' => 'medium',
        'assignee_id' => $this->dev->id,
        'label_ids' => [$this->agentLabel->id],
    ])->assertCreated();

    $data = eligibilityData();

    expect($data['reason'])->toBe('created')
        ->and($data['task_id'])->toBe($response->json('task.id'))
        ->and($data['before'])->toBeNull()
        ->and($data['after'])->toEqual(['assignee_id' => $this->dev->id, 'label_ids' => [$this->agentLabel->id], 'label_names' => ['agent'], 'assignee_can_view_agent_channel' => true])
        ->and($data['task']['title'])->toBe('Add dark mode');
});

it('says nothing about a task filed with no assignee and no labels', function () {
    $this->postJson(route('projects.tasks.store', [$this->workspace->slug, $this->project->slug]), [
        'title' => 'Someday',
        'type' => 'task',
        'priority' => 'medium',
    ])->assertCreated();

    expect(outboundEvents())->toHaveCount(0);
});

it('says nothing when an edit leaves the assignee and labels alone', function () {
    $this->task->forceFill(['assignee_id' => $this->dev->id])->save();
    $this->task->labels()->attach($this->agentLabel);

    updateTask($this, $this->task, [
        'title' => 'Fix the login properly',
        'priority' => 'high',
        'assignee_id' => $this->dev->id,
        'label_ids' => [$this->agentLabel->id],
    ]);

    expect(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(0);
});

it('says nothing when a card only moves column', function () {
    $this->task->forceFill(['assignee_id' => $this->dev->id])->save();

    $this->patchJson(route('tasks.move', [$this->workspace->slug, $this->task->id]), [
        'board_column_id' => projectColumn($this->project, ColumnCategory::Started)->id,
    ])->assertOk();

    expect(outboundEvents())->toHaveCount(0);
});

it('reports a deleted task that had an assignee, with no task left to describe', function () {
    $this->task->forceFill(['assignee_id' => $this->dev->id])->save();
    $this->task->labels()->attach($this->agentLabel);

    $this->deleteJson(route('tasks.destroy', [$this->workspace->slug, $this->task->id]))->assertSuccessful();

    $data = eligibilityData();

    expect($data['reason'])->toBe('deleted')
        ->and($data['task_id'])->toBe($this->task->id)
        ->and($data['reference'])->toBe("PCA-{$this->task->number}")
        ->and($data['task'])->toBeNull()
        ->and($data['after'])->toBeNull()
        ->and($data['before'])->toEqual(['assignee_id' => $this->dev->id, 'label_ids' => [$this->agentLabel->id], 'label_names' => ['agent'], 'assignee_can_view_agent_channel' => true]);
});

it('says nothing about deleting a task nobody could have been working', function () {
    $this->deleteJson(route('tasks.destroy', [$this->workspace->slug, $this->task->id]))->assertSuccessful();

    expect(outboundEvents())->toHaveCount(0);
});

it('reports every open task handed back when somebody leaves the project', function () {
    $this->task->forceFill(['assignee_id' => $this->dev->id])->save();
    $second = makeTask($this->project, $this->owner, ['assignee_id' => $this->dev->id]);
    $finished = makeTask($this->project, $this->owner, ['assignee_id' => $this->dev->id, 'completed_at' => now()]);
    $someoneElses = makeTask($this->project, $this->owner, ['assignee_id' => $this->owner->id]);

    $membership = $this->project->members()->where('user_id', $this->dev->id)->sole();

    $this->delete(route('projects.members.destroy', [$this->workspace->slug, $this->project->slug, $membership->id]))
        ->assertRedirect();

    $events = outboundEvents(OutboundEventType::TaskEligibilityChanged);

    expect($events->map(fn (OutboundEvent $event): int => eligibilityData($event)['task_id'])->all())
        ->toBe([$this->task->id, $second->id])
        ->and($events->every(fn (OutboundEvent $event): bool => eligibilityData($event)['reason'] === 'member_removed'
            && eligibilityData($event)['before']['assignee_id'] === $this->dev->id
            && eligibilityData($event)['after']['assignee_id'] === null
            && eligibilityData($event)['task']['assignee_id'] === null))->toBeTrue()
        ->and($finished->fresh()->assignee_id)->toBe($this->dev->id)
        ->and($someoneElses->fresh()->assignee_id)->toBe($this->owner->id);
});

it('reports every task that wore a deleted label', function () {
    $this->task->labels()->attach($this->agentLabel);
    $second = makeTask($this->project, $this->owner);
    $second->labels()->attach($this->agentLabel);
    makeTask($this->project, $this->owner);

    $this->delete(route('projects.labels.destroy', [$this->workspace->slug, $this->project->slug, $this->agentLabel->id]))
        ->assertRedirect();

    $events = outboundEvents(OutboundEventType::TaskEligibilityChanged);

    expect($events)->toHaveCount(2)
        ->and($events->map(fn (OutboundEvent $event): int => eligibilityData($event)['task_id'])->all())->toBe([$this->task->id, $second->id])
        ->and(eligibilityData($events[0])['reason'])->toBe('label_deleted')
        ->and(eligibilityData($events[0])['before']['label_names'])->toBe(['agent'])
        ->and(eligibilityData($events[0])['after']['label_names'])->toBe([]);
});

it('reports a rename of a label a task wears, and not a recolour', function () {
    $this->task->labels()->attach($this->agentLabel);

    $this->patch(route('projects.labels.update', [$this->workspace->slug, $this->project->slug, $this->agentLabel->id]), ['color' => 'red'])
        ->assertRedirect();

    expect(outboundEvents())->toHaveCount(0);

    $this->patch(route('projects.labels.update', [$this->workspace->slug, $this->project->slug, $this->agentLabel->id]), ['name' => 'ai'])
        ->assertRedirect();

    $data = eligibilityData();

    expect($data['reason'])->toBe('label_renamed')
        ->and($data['before']['label_names'])->toBe(['agent'])
        ->and($data['after']['label_names'])->toBe(['ai'])
        ->and($data['after']['label_ids'])->toBe([$this->agentLabel->id]);
});

it('says nothing for a project whose agents are switched off', function () {
    $quiet = makeProject($this->workspace, $this->owner, ['name' => 'Back Office', 'key' => 'BO']);
    addToProject($quiet, $this->dev);
    $task = makeTask($quiet, $this->owner);

    updateTask($this, $task, ['assignee_id' => $this->dev->id]);

    expect(outboundEvents())->toHaveCount(0);
});

it('carries the project leads and the agent columns by name', function () {
    $lead = joinWorkspace($this->workspace);
    addToProject($this->project, $lead, ProjectRole::Lead);

    $started = projectColumn($this->project, ColumnCategory::Started);
    $this->project->forceFill(['agent_in_progress_column_id' => $started->id])->save();

    updateTask($this, $this->task, ['assignee_id' => $this->dev->id]);

    $project = eligibilityData()['project'];

    expect($project['lead_user_ids'])->toEqualCanonicalizing([$this->owner->id, $lead->id])
        ->and($project['agent']['in_progress_column_id'])->toBe($started->id)
        ->and($project['agent']['in_progress_column_name'])->toBe($started->name);
});
