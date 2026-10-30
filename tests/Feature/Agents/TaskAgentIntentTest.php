<?php

use App\Enums\OutboundEventType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
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

    $this->task = makeTask($this->project, $this->owner);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function agentIntent(object $test, User $actor, Task $task, array $attributes): TestResponse
{
    return $test->actingAs($actor)
        ->patchJson(route('tasks.update', [$test->workspace->slug, $task->id]), $attributes);
}

function reservedLabelId(Project $project, string $name): int
{
    return TaskLabel::query()->where('project_id', $project->id)->where('name', $name)->sole()->id;
}

it('starts your own agent in one save when you assign yourself', function () {
    agentIntent($this, $this->dev, $this->task, ['agent' => 'assign', 'assignee_id' => $this->dev->id])->assertOk();

    $event = outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole();

    expect($event->payload['actor']['user_id'])->toBe($this->dev->id)
        ->and($event->payload['actor']['via'])->toBe('web')
        ->and($event->payload['data']['after']['assignee_id'])->toBe($this->dev->id)
        ->and($event->payload['data']['after']['label_names'])->toBe([TaskLabel::AGENT])
        ->and($event->payload['data']['after']['assignee_can_view_agent_channel'])->toBeTrue();
});

it('defaults an unassigned task to the person asking', function () {
    agentIntent($this, $this->dev, $this->task, ['agent' => 'assign'])->assertOk();

    expect($this->task->fresh()->assignee_id)->toBe($this->dev->id)
        ->and($this->task->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT]);
});

it('only requests the agent when assigning somebody else', function () {
    agentIntent($this, $this->owner, $this->task, ['agent' => 'assign', 'assignee_id' => $this->dev->id])
        ->assertOk()
        ->assertJsonPath('task.assignee.id', $this->dev->id);

    $after = outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole()->payload['data']['after'];

    expect($after['label_names'])->toBe([TaskLabel::AGENT_REQUESTED])
        ->and($after['label_names'])->not->toContain(TaskLabel::AGENT);
});

it('lets the assignee start a requested agent, swapping the label as them', function () {
    agentIntent($this, $this->owner, $this->task, ['agent' => 'assign', 'assignee_id' => $this->dev->id])->assertOk();

    agentIntent($this, $this->dev, $this->task, ['agent' => 'start'])->assertOk();

    $event = outboundEvents(OutboundEventType::TaskEligibilityChanged)->last();

    expect($event->payload['actor']['user_id'])->toBe($this->dev->id)
        ->and($event->payload['data']['before']['label_names'])->toBe([TaskLabel::AGENT_REQUESTED])
        ->and($event->payload['data']['after']['label_names'])->toBe([TaskLabel::AGENT])
        ->and($event->payload['data']['after']['assignee_id'])->toBe($this->dev->id);
});

it('refuses to start somebody else\'s agent', function () {
    agentIntent($this, $this->owner, $this->task, ['agent' => 'assign', 'assignee_id' => $this->dev->id])->assertOk();

    agentIntent($this, $this->owner, $this->task, ['agent' => 'start'])->assertForbidden();

    expect($this->task->fresh()->labels->pluck('name')->all())->toBe([TaskLabel::AGENT_REQUESTED]);
});

it('stops the agent and keeps the other labels', function () {
    $backend = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'backend']);
    $this->task->labels()->attach($backend);

    agentIntent($this, $this->dev, $this->task, ['agent' => 'assign'])->assertOk();
    agentIntent($this, $this->dev, $this->task, ['agent' => 'stop'])->assertOk();

    expect($this->task->fresh()->labels->pluck('name')->all())->toBe(['backend'])
        ->and($this->task->fresh()->assignee_id)->toBe($this->dev->id);
});

it('reuses the reserved labels rather than creating them twice', function () {
    $existing = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => TaskLabel::AGENT]);

    agentIntent($this, $this->dev, $this->task, ['agent' => 'assign'])->assertOk();

    expect(reservedLabelId($this->project, TaskLabel::AGENT))->toBe($existing->id);
});

it('refuses an assignee who is not on the project', function () {
    $outsider = joinWorkspace($this->workspace);

    agentIntent($this, $this->owner, $this->task, ['agent' => 'assign', 'assignee_id' => $outsider->id])
        ->assertJsonValidationErrors('assignee_id');
});

it('refuses when the project has no agents', function () {
    $this->project->forceFill(['agent_channel_id' => null])->save();

    agentIntent($this, $this->dev, $this->task, ['agent' => 'assign'])->assertJsonValidationErrors('agent');

    expect($this->task->fresh()->assignee_id)->toBeNull();
});

it('tells the panel whether agents are on', function () {
    $this->actingAs($this->dev)
        ->get(route('projects.board', [$this->workspace->slug, $this->project->slug]))
        ->assertInertia(fn ($page) => $page->where('project.agents_enabled', true));
});
