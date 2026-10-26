<?php

use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Enums\ColumnCategory;
use App\Enums\OutboundEventType;
use App\Enums\ProjectRole;
use App\Models\AuditLog;
use App\Models\BoardColumn;
use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A project's agent settings: who may change them, what they may point at,
 * and that every change is written down and reported.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->lead = joinWorkspace($this->workspace);
    $this->member = joinWorkspace($this->workspace);

    $this->project = makeProject($this->workspace, $this->lead, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    addToProject($this->project, $this->member);

    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent']);
    $this->started = projectColumn($this->project, ColumnCategory::Started);
    $this->review = BoardColumn::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'In review',
        'category' => ColumnCategory::Started,
        'position' => 1500,
    ]);
});

/**
 * @param  array<string, mixed>  $settings
 */
function saveAgentSettings(TestCase $test, User $user, array $settings, ?Project $project = null): TestResponse
{
    $project ??= $test->project;

    return $test->actingAs($user)->patch(
        route('projects.update', [$test->workspace->slug, $project->slug]),
        $settings,
    );
}

it('lets a project lead switch agents on, and records and reports it', function () {
    saveAgentSettings($this, $this->lead, [
        'agent_channel_id' => $this->channel->id,
        'agent_in_progress_column_id' => $this->started->id,
        'agent_review_column_id' => $this->review->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $project = $this->project->fresh();

    expect($project->agent_channel_id)->toBe($this->channel->id)
        ->and($project->agent_in_progress_column_id)->toBe($this->started->id)
        ->and($project->agent_review_column_id)->toBe($this->review->id)
        ->and($project->agentsEnabled())->toBeTrue();

    $audit = AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->sole();

    expect($audit->user_id)->toBe($this->lead->id)
        ->and($audit->old_values)->toEqual([
            'agent_channel_id' => null,
            'agent_in_progress_column_id' => null,
            'agent_review_column_id' => null,
        ])
        ->and($audit->new_values)->toEqual([
            'agent_channel_id' => $this->channel->id,
            'agent_in_progress_column_id' => $this->started->id,
            'agent_review_column_id' => $this->review->id,
        ])
        ->and(AuditLog::query()->where('action', AuditAction::ProjectUpdated)->count())->toBe(0);

    $data = outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data'];

    expect($data['before'])->toEqual([
        'channel_id' => null, 'channel_name' => null,
        'in_progress_column_id' => null, 'in_progress_column_name' => null,
        'review_column_id' => null, 'review_column_name' => null,
    ])
        ->and($data['project']['id'])->toBe($this->project->id)
        ->and($data['project']['agent'])->toEqual([
            'channel_id' => $this->channel->id, 'channel_name' => 'pca-agent',
            'in_progress_column_id' => $this->started->id, 'in_progress_column_name' => $this->started->name,
            'review_column_id' => $this->review->id, 'review_column_name' => 'In review',
        ]);
});

it('reports switching agents off, with what they were before', function () {
    $this->project->forceFill(['agent_channel_id' => $this->channel->id])->save();

    saveAgentSettings($this, $this->lead, ['agent_channel_id' => null])->assertSessionHasNoErrors();

    $data = outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data'];

    expect($this->project->fresh()->agentsEnabled())->toBeFalse()
        ->and($data['before']['channel_id'])->toBe($this->channel->id)
        ->and($data['before']['channel_name'])->toBe('pca-agent')
        ->and($data['project']['agent']['channel_id'])->toBeNull();
});

it('records nothing when nothing changed, or only something else did', function () {
    $this->project->forceFill(['agent_channel_id' => $this->channel->id])->save();

    saveAgentSettings($this, $this->lead, ['agent_channel_id' => $this->channel->id])->assertSessionHasNoErrors();
    saveAgentSettings($this, $this->lead, ['description' => 'The rider app'])->assertSessionHasNoErrors();

    expect(outboundEvents())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ProjectUpdated)->count())->toBe(1);
});

it('is a lead\'s decision, not a member\'s', function () {
    saveAgentSettings($this, $this->member, ['agent_channel_id' => $this->channel->id])->assertForbidden();

    expect($this->project->fresh()->agent_channel_id)->toBeNull()
        ->and(outboundEvents())->toHaveCount(0);
});

it('cannot be changed on an archived project', function () {
    $this->project->forceFill(['is_archived' => true])->save();

    saveAgentSettings($this, $this->lead, ['agent_channel_id' => $this->channel->id])->assertForbidden();
});

it('refuses a channel the project cannot use', function (Channel $channel) {
    saveAgentSettings($this, $this->lead, ['agent_channel_id' => $channel->id])
        ->assertSessionHasErrors('agent_channel_id');

    expect($this->project->fresh()->agent_channel_id)->toBeNull()
        ->and(outboundEvents())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->count())->toBe(0);
})->with([
    'in another workspace' => fn () => makeChannel(makeWorkspace()),
    'an announcement channel' => fn () => makeChannel($this->workspace, ChannelType::Announcement),
    'an archived channel' => fn () => Channel::factory()->forWorkspace($this->workspace)->archived()->create(),
    'a private channel the lead is not in' => fn () => makeChannel($this->workspace, ChannelType::Private),
    'one that does not exist' => fn () => (new Channel)->forceFill(['id' => 999999]),
]);

it('accepts a private channel the lead is in', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($private, $this->lead);

    saveAgentSettings($this, $this->lead, ['agent_channel_id' => $private->id])->assertSessionHasNoErrors();

    expect($this->project->fresh()->agent_channel_id)->toBe($private->id);
});

it('lets a lead who cannot see the current channel still change the columns', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);
    $this->project->forceFill(['agent_channel_id' => $private->id])->save();

    saveAgentSettings($this, $this->lead, [
        'agent_channel_id' => $private->id,
        'agent_review_column_id' => $this->review->id,
    ])->assertSessionHasNoErrors();

    expect($this->project->fresh()->agent_review_column_id)->toBe($this->review->id);
});

it('refuses a column from another project', function () {
    $other = makeProject($this->workspace, $this->lead, ['name' => 'Other', 'key' => 'OTH']);

    saveAgentSettings($this, $this->lead, [
        'agent_in_progress_column_id' => projectColumn($other, ColumnCategory::Started)->id,
    ])->assertSessionHasErrors('agent_in_progress_column_id');

    expect($this->project->fresh()->agent_in_progress_column_id)->toBeNull();
});

it('audits the change even while no dispatcher is configured', function () {
    config(['snack.agents.dispatcher_url' => null]);

    saveAgentSettings($this, $this->lead, ['agent_channel_id' => $this->channel->id])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->count())->toBe(1)
        ->and(outboundEvents())->toHaveCount(0);
});

it('switches agents off by itself when the channel is deleted', function () {
    $this->project->forceFill(['agent_channel_id' => $this->channel->id])->save();
    addToProject($this->project, $dev = joinWorkspace($this->workspace));

    $this->channel->delete();

    $project = $this->project->fresh();
    $task = makeTask($project, $this->lead);

    $this->actingAs($this->lead)
        ->patchJson(route('tasks.update', [$this->workspace->slug, $task->id]), ['assignee_id' => $dev->id])
        ->assertOk();

    expect($project->agent_channel_id)->toBeNull()
        ->and($project->agentsEnabled())->toBeFalse()
        ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(0);
});

it('clears and reports a column the agents were using when it is deleted', function () {
    $this->project->forceFill([
        'agent_channel_id' => $this->channel->id,
        'agent_review_column_id' => $this->review->id,
    ])->save();

    $this->actingAs($this->lead)
        ->delete(route('projects.columns.destroy', [$this->workspace->slug, $this->project->slug, $this->review->id]))
        ->assertSessionHasNoErrors();

    $data = outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data'];

    expect($this->project->fresh()->agent_review_column_id)->toBeNull()
        ->and($data['before']['review_column_id'])->toBe($this->review->id)
        ->and($data['before']['review_column_name'])->toBe('In review')
        ->and($data['project']['agent']['review_column_id'])->toBeNull()
        ->and($data['project']['agent']['channel_id'])->toBe($this->channel->id);
});

it('says nothing about deleting a column the agents were not using', function () {
    $this->project->forceFill(['agent_channel_id' => $this->channel->id])->save();

    $this->actingAs($this->lead)
        ->delete(route('projects.columns.destroy', [$this->workspace->slug, $this->project->slug, $this->review->id]))
        ->assertSessionHasNoErrors();

    expect(outboundEvents())->toHaveCount(0);
});

it('offers only channels the viewer could choose, and the project\'s columns', function () {
    $announcements = makeChannel($this->workspace, ChannelType::Announcement, ['name' => 'announcements']);
    $hidden = makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders']);
    $mine = makeChannel($this->workspace, ChannelType::Private, ['name' => 'leads']);
    addToChannel($mine, $this->lead);
    $archived = Channel::factory()->forWorkspace($this->workspace)->archived()->create(['name' => 'old-stuff']);
    $elsewhere = makeChannel(makeWorkspace(), attributes: ['name' => 'elsewhere']);

    $this->actingAs($this->lead)
        ->get(route('projects.settings.edit', [$this->workspace->slug, $this->project->slug]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($announcements, $hidden, $mine, $archived, $elsewhere) {
            $ids = collect($page->toArray()['props']['agentChannels'])->pluck('id');

            expect($ids)->toContain($this->channel->id, $mine->id)
                ->not->toContain($announcements->id)
                ->not->toContain($hidden->id)
                ->not->toContain($archived->id)
                ->not->toContain($elsewhere->id);

            $page->where('columns', $this->project->columns()->ordered()->get()
                ->map(fn (BoardColumn $column): array => ['id' => $column->id, 'name' => $column->name])
                ->all())
                ->where('project.agent_channel_id', null);
        });
});

it('shows the settings read-only to a member', function () {
    $this->actingAs($this->member)
        ->get(route('projects.settings.edit', [$this->workspace->slug, $this->project->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('agentChannels'));
});

it('treats a workspace admin as able to change them', function () {
    saveAgentSettings($this, $this->owner, ['agent_channel_id' => $this->channel->id])->assertSessionHasNoErrors();

    expect($this->project->fresh()->agent_channel_id)->toBe($this->channel->id)
        ->and($this->project->roleFor($this->owner))->not->toBe(ProjectRole::Lead);
});
