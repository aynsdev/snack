<?php

use App\Actions\Connectors\DisconnectConnector;
use App\Actions\Projects\SaveTask;
use App\Actions\Projects\UpdateProject;
use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetWorkspaceMemberStatus;
use App\Enums\ChannelType;
use App\Enums\ConnectorKind;
use App\Enums\MemberStatus;
use App\Enums\OutboundEventType;
use App\Models\Channel;
use App\Models\OAuthToken;
use App\Models\OutboundEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Queue;

/**
 * Contract v1.1: when agents count as switched on, and every way they stop —
 * archiving, the channel changing under them, a task's content changing after
 * its plan was made, a connector revoked, a person leaving.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);

    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->owner);

    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    addToProject($this->project, $this->dev);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the login', 'description' => 'It times out.']);
});

/**
 * @return array<string, mixed>
 */
function lastData(OutboundEventType $type): array
{
    return outboundEvents($type)->last()->payload['data'];
}

function assignTo(object $test, Task $task, User $user): void
{
    $test->actingAs($test->owner)
        ->patchJson(route('tasks.update', [$test->workspace->slug, $task->id]), ['assignee_id' => $user->id])
        ->assertOk();
}

describe('agents count as on only while project and channel are live', function () {
    it('says nothing about tasks on an archived project', function () {
        $this->project->forceFill(['is_archived' => true])->save();

        app(SaveTask::class)->update($this->task, $this->owner, ['assignee_id' => $this->dev->id]);

        expect($this->project->fresh()->agentsEnabled())->toBeFalse()
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(0);
    });

    it('says nothing about tasks while the agent channel is archived', function () {
        $this->channel->forceFill(['is_archived' => true])->save();

        assignTo($this, $this->task, $this->dev);

        expect($this->project->fresh()->agentsEnabled())->toBeFalse()
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(0);
    });

    it('reports archiving and restoring the project, with `archived` set', function () {
        $this->actingAs($this->owner)
            ->post(route('projects.archive.store', [$this->workspace->slug, $this->project->slug]))
            ->assertRedirect();

        $archived = lastData(OutboundEventType::ProjectAgentSettingsChanged);

        expect($archived['project']['archived'])->toBeTrue()
            ->and($archived['project']['agent']['channel_id'])->toBe($this->channel->id)
            ->and($archived['before']['channel_id'])->toBe($this->channel->id);

        $this->actingAs($this->owner)
            ->delete(route('projects.archive.destroy', [$this->workspace->slug, $this->project->slug]))
            ->assertRedirect();

        expect(lastData(OutboundEventType::ProjectAgentSettingsChanged)['project']['archived'])->toBeFalse()
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(2);
    });

    it('says nothing when archiving a project whose agents were never set up', function () {
        $quiet = makeProject($this->workspace, $this->owner, ['name' => 'Back Office', 'key' => 'BO']);

        $this->actingAs($this->owner)
            ->post(route('projects.archive.store', [$this->workspace->slug, $quiet->slug]))
            ->assertRedirect();

        expect(outboundEvents())->toHaveCount(0);
    });
});

describe('the agent channel changing underneath', function () {
    beforeEach(function () {
        $this->second = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Driver App', 'key' => 'PDA']);
    });

    it('reports archiving and restoring the channel to every project using it', function () {
        $this->actingAs($this->owner)
            ->post(route('channels.archive.store', [$this->workspace->slug, $this->channel->slug]))
            ->assertRedirect();

        $events = outboundEvents(OutboundEventType::ProjectAgentSettingsChanged);

        expect($events->map(fn (OutboundEvent $event): int => $event->payload['data']['project']['id'])->all())
            ->toBe([$this->project->id, $this->second->id])
            ->and($events->every(fn (OutboundEvent $event): bool => $event->payload['data']['before']['channel_id'] === $this->channel->id
                && $event->payload['data']['project']['agent']['channel_id'] === null
                && $event->payload['data']['project']['agent']['channel_name'] === null))->toBeTrue();

        $this->actingAs($this->owner)
            ->delete(route('channels.archive.destroy', [$this->workspace->slug, $this->channel->slug]))
            ->assertRedirect();

        expect(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(4)
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['before']['channel_id'])->toBeNull()
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['project']['agent']['channel_id'])->toBe($this->channel->id);
    });

    it('reports a rename with the old name before and the new one after', function () {
        $this->actingAs($this->owner)
            ->patch(route('channels.update', [$this->workspace->slug, $this->channel->slug]), ['name' => 'PCA agents'])
            ->assertRedirect();

        $data = outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->first()->payload['data'];

        expect(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(2)
            ->and($data['before']['channel_name'])->toBe('pca-agent')
            ->and($data['project']['agent']['channel_name'])->toBe('PCA agents');
    });

    it('reports a change of who can see the channel', function () {
        $this->actingAs($this->owner)
            ->patch(route('channels.update', [$this->workspace->slug, $this->channel->slug]), ['type' => 'private'])
            ->assertRedirect();

        expect(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(2)
            ->and($this->project->fresh()->agent_channel_id)->toBe($this->channel->id);
    });

    it('takes an announcement channel away from the projects using it', function () {
        $this->actingAs($this->owner)
            ->patch(route('channels.update', [$this->workspace->slug, $this->channel->slug]), ['type' => 'announcement'])
            ->assertRedirect();

        expect($this->project->fresh()->agent_channel_id)->toBeNull()
            ->and($this->second->fresh()->agent_channel_id)->toBeNull()
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(2)
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['project']['agent']['channel_id'])->toBeNull()
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['before']['channel_id'])->toBe($this->channel->id);
    });

    it('says nothing about a new topic or description', function () {
        $this->actingAs($this->owner)
            ->patch(route('channels.update', [$this->workspace->slug, $this->channel->slug]), ['topic' => 'Agents only', 'description' => 'Where agents post'])
            ->assertRedirect();

        expect(outboundEvents())->toHaveCount(0);
    });

    it('reports a deleted channel before it goes', function () {
        $this->channel->delete();

        expect(Channel::query()->find($this->channel->id))->toBeNull()
            ->and($this->project->fresh()->agent_channel_id)->toBeNull()
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(2)
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['before']['channel_name'])->toBe('pca-agent')
            ->and(lastData(OutboundEventType::ProjectAgentSettingsChanged)['project']['agent']['channel_id'])->toBeNull();
    });
});

describe('whether the assignee can see the agent channel', function () {
    it('is false for an assignee who is not in a private agent channel, and true once they are', function () {
        $private = makeChannel($this->workspace, ChannelType::Private, ['name' => 'pca-secret']);
        addToChannel($private, $this->owner);
        $this->project->forceFill(['agent_channel_id' => $private->id])->save();

        assignTo($this, $this->task, $this->dev);

        expect(lastData(OutboundEventType::TaskEligibilityChanged)['after']['assignee_can_view_agent_channel'])->toBeFalse();

        addToChannel($private, $this->dev);
        $second = makeTask($this->project, $this->owner);

        assignTo($this, $second, $this->dev);

        expect(lastData(OutboundEventType::TaskEligibilityChanged)['after']['assignee_can_view_agent_channel'])->toBeTrue()
            ->and(lastData(OutboundEventType::TaskEligibilityChanged)['before']['assignee_can_view_agent_channel'])->toBeFalse();
    });

    it('is true for a public agent channel the assignee has not joined', function () {
        assignTo($this, $this->task, $this->dev);

        expect(lastData(OutboundEventType::TaskEligibilityChanged)['after']['assignee_can_view_agent_channel'])->toBeTrue();
    });
});

describe('task.content_changed', function () {
    it('reports a new title, and a new title and description together', function () {
        $this->actingAs($this->owner)
            ->patchJson(route('tasks.update', [$this->workspace->slug, $this->task->id]), ['title' => 'Fix the login for real'])
            ->assertOk();

        $data = lastData(OutboundEventType::TaskContentChanged);

        expect($data['changed'])->toBe(['title'])
            ->and($data['task_id'])->toBe($this->task->id)
            ->and($data['reference'])->toBe("PCA-{$this->task->number}")
            ->and($data['task']['title'])->toBe('Fix the login for real')
            ->and($data['project']['id'])->toBe($this->project->id);

        $this->patchJson(route('tasks.update', [$this->workspace->slug, $this->task->id]), [
            'title' => 'Fix the login, finally',
            'description' => 'It times out after 30s.',
        ])->assertOk();

        expect(lastData(OutboundEventType::TaskContentChanged)['changed'])->toBe(['title', 'description'])
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(0);
    });

    it('says nothing for other fields, unchanged text, or a project with agents off', function () {
        $this->actingAs($this->owner)
            ->patchJson(route('tasks.update', [$this->workspace->slug, $this->task->id]), ['priority' => 'high', 'title' => 'Fix the login'])
            ->assertOk();

        $quiet = makeProject($this->workspace, $this->owner, ['name' => 'Back Office', 'key' => 'BO']);
        $quietTask = makeTask($quiet, $this->owner);

        $this->patchJson(route('tasks.update', [$this->workspace->slug, $quietTask->id]), ['title' => 'Renamed'])
            ->assertOk();

        expect(outboundEvents(OutboundEventType::TaskContentChanged))->toHaveCount(0);
    });
});

describe('connectors and people going away', function () {
    it('reports an agent connector being revoked, and not an ordinary one', function () {
        connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
        connectAs($this->dev, $this->workspace);

        $agent = OAuthToken::query()->where('connector_kind', ConnectorKind::Agent)->sole();
        $plain = OAuthToken::query()->where('connector_kind', ConnectorKind::Token)->sole();

        app(DisconnectConnector::class)->token($this->workspace, $plain, $this->dev);
        app(DisconnectConnector::class)->token($this->workspace, $agent, $this->dev);

        expect(outboundEvents(OutboundEventType::AgentConnectorRevoked)->sole()->payload['data'])
            ->toEqual(['user_id' => $this->dev->id, 'connector_id' => $agent->id]);
    });

    it('reports somebody removed from the workspace', function () {
        $membership = WorkspaceMember::query()->where('user_id', $this->dev->id)->sole();

        app(RemoveWorkspaceMember::class)->handle($membership, $this->owner);

        expect(lastData(OutboundEventType::WorkspaceMemberRemoved))->toEqual(['user_id' => $this->dev->id, 'reason' => 'removed']);
    });

    it('reports a suspension once, and not the reinstatement', function () {
        $membership = WorkspaceMember::query()->where('user_id', $this->dev->id)->sole();

        app(SetWorkspaceMemberStatus::class)->handle($membership, MemberStatus::Suspended, $this->owner);
        app(SetWorkspaceMemberStatus::class)->handle($membership->fresh(), MemberStatus::Suspended, $this->owner);
        app(SetWorkspaceMemberStatus::class)->handle($membership->fresh(), MemberStatus::Active, $this->owner);

        expect(outboundEvents(OutboundEventType::WorkspaceMemberRemoved))->toHaveCount(1)
            ->and(lastData(OutboundEventType::WorkspaceMemberRemoved))->toEqual(['user_id' => $this->dev->id, 'reason' => 'deactivated']);
    });

    it('hands back a deleted account\'s tasks, then reports it gone from every workspace', function () {
        $this->dev->forceFill(['password' => 'password'])->save();
        [$otherWorkspace] = makeWorkspaceWithOwner();
        joinWorkspace($otherWorkspace, $this->dev, status: MemberStatus::Suspended);

        $label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);
        $this->task->forceFill(['assignee_id' => $this->dev->id])->save();
        $this->task->labels()->attach($label);
        $finished = makeTask($this->project, $this->owner, ['assignee_id' => $this->dev->id, 'completed_at' => now()]);
        $quiet = makeProject($this->workspace, $this->owner, ['name' => 'Back Office', 'key' => 'BO']);
        $quietTask = makeTask($quiet, $this->owner, ['assignee_id' => $this->dev->id]);

        $this->actingAs($this->dev)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        expect(User::query()->find($this->dev->id))->toBeNull()
            ->and($this->task->fresh()->assignee_id)->toBeNull()
            ->and($quietTask->fresh()->assignee_id)->toBeNull();

        $events = outboundEvents();

        expect($events->pluck('type')->map->value->all())->toBe([
            'task.eligibility_changed',
            'task.eligibility_changed',
            'workspace.member_removed',
            'workspace.member_removed',
        ])
            ->and($events->take(2)->map(fn (OutboundEvent $event): int => $event->payload['data']['task_id'])->all())
            ->toBe([$this->task->id, $finished->id])
            ->and($events[0]->payload['data']['reason'])->toBe('member_removed')
            ->and($events[0]->payload['data']['after']['assignee_id'])->toBeNull()
            ->and($events[0]->payload['actor']['via'])->toBe('web')
            ->and($events[0]->payload['actor']['user_id'])->toBe($this->dev->id)
            ->and($events->slice(2)->map(fn (OutboundEvent $event): array => [$event->workspace_id, $event->payload['data']])->values()->all())
            ->toEqual([
                [$this->workspace->id, ['user_id' => $this->dev->id, 'reason' => 'deleted']],
                [$otherWorkspace->id, ['user_id' => $this->dev->id, 'reason' => 'deleted']],
            ]);
    });
});

describe('settings read under a lock', function () {
    it('describes the "before" from the stored row, not a stale copy', function () {
        $stale = Project::query()->findOrFail($this->project->id);

        // Somebody else cleared the channel since this copy was loaded.
        Project::query()->whereKey($this->project->id)->update(['agent_channel_id' => null]);

        app(UpdateProject::class)->handle($stale, $this->owner, ['agent_channel_id' => $this->channel->id]);

        $data = lastData(OutboundEventType::ProjectAgentSettingsChanged);

        expect($data['before']['channel_id'])->toBeNull()
            ->and($data['project']['agent']['channel_id'])->toBe($this->channel->id)
            ->and($stale->agent_channel_id)->toBe($this->channel->id);
    });

    it('does not report a change a stale copy only imagined', function () {
        $stale = Project::query()->findOrFail($this->project->id);
        $stale->agent_channel_id = null;
        $stale->syncOriginal();

        app(UpdateProject::class)->handle($stale, $this->owner, ['agent_channel_id' => $this->channel->id]);

        expect(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(0);
    });

    it('unassigns exactly the cards it locked when somebody leaves the project', function () {
        $this->task->forceFill(['assignee_id' => $this->dev->id])->save();
        $membership = $this->project->members()->where('user_id', $this->dev->id)->sole();

        $this->actingAs($this->owner)
            ->delete(route('projects.members.destroy', [$this->workspace->slug, $this->project->slug, $membership->id]))
            ->assertRedirect();

        expect($this->task->fresh()->assignee_id)->toBeNull()
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(1);
    });
});
