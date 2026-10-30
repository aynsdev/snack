<?php

use App\Actions\Channels\LeaveChannel;
use App\Actions\Channels\RemoveChannelMember;
use App\Actions\Channels\UpdateChannel;
use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\SendMessage;
use App\Actions\Profile\DeleteAccount;
use App\Actions\Projects\CompleteSprint;
use App\Actions\Projects\DeleteBoardColumn;
use App\Actions\Projects\MoveTask;
use App\Actions\Projects\SaveTask;
use App\Actions\Projects\UpdateProject;
use App\Actions\Workspaces\UpdateWorkspaceMemberRole;
use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Enums\ColumnCategory;
use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverOutboundEvent;
use App\Models\AuditLog;
use App\Models\BoardColumn;
use App\Models\OutboundEvent;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\WorkspaceMember;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The outbox's concurrency rule: a change that may report takes its
 * workspace's lock before it writes anything, reads what it reports under
 * that lock, and writes its events last, built from the state it left behind.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
    $this->channel = makeChannel($this->workspace, ChannelType::Private, ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->dev);
    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    addToProject($this->project, $this->dev);

    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the login', 'assignee_id' => $this->dev->id]);
});

/**
 * Every statement run by the callback, lower-cased.
 *
 * @return array<int, string>
 */
function statementsDuring(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_map(fn (array $query): string => strtolower($query['query']), DB::getQueryLog());
}

/**
 * The position of the first statement containing the needle, or null.
 *
 * @param  array<int, string>  $statements
 */
function firstStatement(array $statements, string $needle): ?int
{
    foreach ($statements as $index => $statement) {
        if (str_contains($statement, $needle)) {
            return $index;
        }
    }

    return null;
}

/**
 * The position of the first write.
 *
 * @param  array<int, string>  $statements
 */
function firstWrite(array $statements): ?int
{
    foreach ($statements as $index => $statement) {
        if (preg_match('/^(insert|update|delete)\b/', $statement) === 1) {
            return $index;
        }
    }

    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function eligibilityEvents(): array
{
    return outboundEvents(OutboundEventType::TaskEligibilityChanged)->map(fn (OutboundEvent $event): array => $event->payload['data'])->all();
}

describe('the workspace lock', function () {
    it('is taken before filing a task writes the project counter', function () {
        $statements = statementsDuring(fn () => app(SaveTask::class)->create($this->project, $this->owner, ['title' => 'New', 'assignee_id' => $this->dev->id]));

        $lock = firstStatement($statements, 'pg_advisory_xact_lock');

        expect($lock)->not->toBeNull()
            ->and($lock)->toBeLessThan(firstStatement($statements, 'update projects set next_task_number'))
            ->and($lock)->toBeLessThan(firstWrite($statements))
            ->and(firstStatement($statements, 'set local lock_timeout'))->toBeLessThan($lock);
    });

    it('is taken before a reply is written or counted, and before a deleted reply is released from its thread', function () {
        $root = makeMessage($this->channel, $this->owner, 'Plan');

        $sent = statementsDuring(function () use ($root) {
            $this->reply = app(SendMessage::class)->handle($this->channel, $this->dev, 'looks good', $root);
        });

        expect(firstStatement($sent, 'pg_advisory_xact_lock'))->toBeLessThan(firstWrite($sent));

        $deleted = statementsDuring(fn () => app(DeleteMessage::class)->handle($this->reply, $this->dev));

        expect(firstStatement($deleted, 'pg_advisory_xact_lock'))->toBeLessThan(firstWrite($deleted))
            ->and(firstStatement($deleted, 'pg_advisory_xact_lock'))->toBeLessThan(firstStatement($deleted, 'update messages as root'));
    });

    it('is not taken for writes nothing is reported about', function (string $what) {
        $general = makeChannel($this->workspace, attributes: ['name' => 'general']);
        addToChannel($general, $this->owner);
        $other = makeProject($this->workspace, $this->owner, ['name' => 'Elsewhere', 'key' => 'ELS']);
        $otherTask = makeTask($other, $this->owner);
        $generalRoot = makeMessage($general, $this->owner);

        $statements = statementsDuring(fn () => match ($what) {
            'a priority change' => app(SaveTask::class)->update($this->task, $this->owner, ['priority' => 'high']),
            'an edit in a project without agents' => app(SaveTask::class)->update($otherTask, $this->owner, ['title' => 'Renamed', 'assignee_id' => $this->owner->id]),
            'a root post in the agent channel' => app(SendMessage::class)->handle($this->channel, $this->owner, 'lunch?'),
            'a reply in another channel' => app(SendMessage::class)->handle($general, $this->owner, 'reply', $generalRoot),
        });

        expect(firstStatement($statements, 'pg_advisory_xact_lock'))->toBeNull()
            ->and(firstStatement($statements, 'for no key update'))->toBeNull();
    })->with(['a priority change', 'an edit in a project without agents', 'a root post in the agent channel', 'a reply in another channel']);

    it('locks every workspace an account deletion reports to, in order, before deleting anything', function () {
        [$second] = makeWorkspaceWithOwner();
        joinWorkspace($second, $this->dev);

        $statements = statementsDuring(fn () => app(DeleteAccount::class)->handle($this->dev));

        $locks = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock'))
            ->map(fn (array $query): int => $query['bindings'][1])
            ->values()
            ->all();

        expect(array_slice($locks, 0, 2))->toBe([$this->workspace->id, $second->id])
            ->and(firstStatement($statements, 'pg_advisory_xact_lock'))->toBeLessThan(firstWrite($statements))
            ->and(collect(eligibilityEvents())->pluck('task_id')->all())->toBe([$this->task->id])
            ->and(outboundEvents(OutboundEventType::WorkspaceMemberRemoved)->pluck('workspace_id')->all())->toBe([$this->workspace->id, $second->id]);
    });

    it('makes a writer wait for another holding the workspace, before it has written anything', function () {
        /*
         * Two sessions: the rival takes this workspace's lock and holds it, as
         * a concurrent change would. Advisory locks are on numbers, so the
         * rival needs none of this test's uncommitted rows to take it.
         */
        config(['database.connections.outbox_rival' => config('database.connections.'.config('database.default'))]);
        config(['snack.agents.lock_timeout' => '300ms']);
        $rival = DB::connection('outbox_rival');

        $rival->beginTransaction();
        $rival->select('SELECT pg_advisory_xact_lock(?, ?)', [OutboundEvents::LOCK_NAMESPACE, $this->workspace->id]);

        $before = $this->project->fresh()->next_task_number;

        try {
            expect(fn () => app(SaveTask::class)->create($this->project, $this->owner, ['title' => 'Blocked']))
                ->toThrow(QueryException::class, 'pg_advisory_xact_lock');
        } finally {
            $rival->rollBack();
            $rival->disconnect();
        }

        expect($this->project->fresh()->next_task_number)->toBe($before)
            ->and(Task::query()->where('title', 'Blocked')->exists())->toBeFalse();
    });
});

describe('the buffer', function () {
    it('drops events noted inside a savepoint that rolled back, and keeps the rest', function () {
        $events = app(OutboundEvents::class);

        $events->transaction([$this->workspace->id], function () use ($events) {
            $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['n' => 1]);

            try {
                DB::transaction(function () use ($events) {
                    $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['n' => 2]);

                    throw new RuntimeException('the inner change failed');
                });
            } catch (RuntimeException) {
            }

            DB::transaction(fn () => $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['n' => 3]));
        });

        expect(outboundEvents()->map(fn (OutboundEvent $event): array => [$event->sequence, $event->payload['data']['n']])->all())
            ->toBe([[1, 1], [2, 3]]);
    });

    it('refuses an event for a workspace the transaction has not locked', function () {
        [$other] = makeWorkspaceWithOwner();
        $events = app(OutboundEvents::class);

        expect(fn () => $events->transaction([$this->workspace->id], fn () => $events->record(OutboundEventType::ProjectAgentSettingsChanged, $other, [])))
            ->toThrow(LogicException::class)
            ->and(fn () => $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, []))
            ->toThrow(LogicException::class);
    });

    it('queues delivery once the change has committed, and not for one that rolled back', function () {
        $events = app(OutboundEvents::class);

        try {
            $events->transaction([$this->workspace->id], function () use ($events) {
                $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['n' => 1]);

                throw new RuntimeException('the change failed');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();

        recordOutboundEvent($this->workspace);

        Queue::assertPushed(DeliverOutboundEvent::class, 1);
    });
});

describe('payloads from the end state', function () {
    it('reports an edit\'s labels, then its new content, both as the edit left them', function () {
        $agent = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);

        app(SaveTask::class)->update($this->task, $this->owner, ['title' => 'Fix the login page', 'label_ids' => [$agent->id]]);

        $events = outboundEvents();

        expect($events->pluck('type')->map->value->all())->toBe(['task.eligibility_changed', 'task.content_changed'])
            ->and($events->pluck('sequence')->all())->toBe([1, 2])
            ->and($events[1]->payload['data']['task']['label_names'])->toBe(['agent'])
            ->and($events[1]->payload['data']['task']['title'])->toBe('Fix the login page')
            ->and($events[0]->payload['data']['task']['title'])->toBe('Fix the login page');
    });

    it('applies an edit to the task as committed, not the copy the request loaded', function () {
        $stale = Task::query()->findOrFail($this->task->id);
        Task::query()->whereKey($this->task->id)->update(['title' => 'Renamed meanwhile', 'description' => 'Changed meanwhile']);

        app(SaveTask::class)->update($stale, $this->owner, ['assignee_id' => $this->owner->id]);

        $data = eligibilityEvents()[0];

        expect($data['task']['title'])->toBe('Renamed meanwhile')
            ->and($data['task']['description'])->toBe('Changed meanwhile')
            ->and($stale->title)->toBe('Renamed meanwhile')
            ->and(outboundEvents(OutboundEventType::TaskContentChanged))->toHaveCount(0);
    });
});

describe('who can see the agent channel', function () {
    it('reports a member removed from a private agent channel', function () {
        app(RemoveChannelMember::class)->handle($this->channel, $this->owner, $this->dev);

        $data = eligibilityEvents();

        expect($data)->toHaveCount(1)
            ->and($data[0]['reason'])->toBe('updated')
            ->and($data[0]['task_id'])->toBe($this->task->id)
            ->and($data[0]['before']['assignee_can_view_agent_channel'])->toBeTrue()
            ->and($data[0]['after']['assignee_can_view_agent_channel'])->toBeFalse();
    });

    it('reports a member leaving, but not for done tasks or other people\'s', function () {
        makeTask($this->project, $this->owner, ['assignee_id' => $this->dev->id, 'completed_at' => now()]);
        makeTask($this->project, $this->owner, ['assignee_id' => $this->owner->id]);

        app(LeaveChannel::class)->handle($this->channel, $this->dev);

        expect(collect(eligibilityEvents())->pluck('task_id')->all())->toBe([$this->task->id]);
    });

    it('says nothing when leaving a public agent channel, which they can still read', function () {
        $this->channel->forceFill(['type' => ChannelType::Public])->save();

        app(LeaveChannel::class)->handle($this->channel, $this->dev);

        expect(eligibilityEvents())->toBe([]);
    });

    it('reports a member demoted to guest, who no longer sees a public channel they never joined', function () {
        $this->channel->forceFill(['type' => ChannelType::Public])->save();
        $this->channel->members()->where('user_id', $this->dev->id)->delete();
        $membership = WorkspaceMember::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->dev->id)->firstOrFail();

        app(UpdateWorkspaceMemberRole::class)->handle($membership, WorkspaceRole::Guest, $this->owner);

        $data = eligibilityEvents();

        expect($data)->toHaveCount(1)
            ->and($data[0]['before']['assignee_can_view_agent_channel'])->toBeTrue()
            ->and($data[0]['after']['assignee_can_view_agent_channel'])->toBeFalse();
    });

    it('reports assignees who lose sight of the channel when it is made private', function () {
        $this->channel->forceFill(['type' => ChannelType::Public])->save();
        $outsider = joinWorkspace($this->workspace);
        addToProject($this->project, $outsider);
        $theirs = makeTask($this->project, $this->owner, ['assignee_id' => $outsider->id]);

        app(UpdateChannel::class)->handle($this->channel, $this->owner, ['type' => ChannelType::Private]);

        $data = eligibilityEvents();

        expect(collect($data)->pluck('task_id')->all())->toBe([$theirs->id])
            ->and($data[0]['after']['assignee_can_view_agent_channel'])->toBeFalse()
            ->and(outboundEvents()->first()->type)->toBe(OutboundEventType::ProjectAgentSettingsChanged);
    });

    it('reports tasks against the new channel when a lead switches it', function () {
        $public = makeChannel($this->workspace, attributes: ['name' => 'agents-public']);
        $outsider = joinWorkspace($this->workspace);
        addToProject($this->project, $outsider);
        $theirs = makeTask($this->project, $this->owner, ['assignee_id' => $outsider->id]);

        app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_channel_id' => $public->id]);

        $data = eligibilityEvents();

        expect(collect($data)->pluck('task_id')->all())->toBe([$theirs->id])
            ->and($data[0]['before']['assignee_can_view_agent_channel'])->toBeFalse()
            ->and($data[0]['after']['assignee_can_view_agent_channel'])->toBeTrue()
            ->and($data[0]['project']['agent']['channel_id'])->toBe($public->id);
    });

    it('reports no task when a lead switches agents off', function () {
        app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_channel_id' => null]);

        expect(eligibilityEvents())->toBe([])
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged))->toHaveCount(1);
    });
});

describe('settings checked again under the lock', function () {
    it('refuses a channel that became unusable after the request was validated', function () {
        $channel = makeChannel($this->workspace, ChannelType::Announcement, ['name' => 'news']);
        addToChannel($channel, $this->owner);

        expect(fn () => app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_channel_id' => $channel->id]))
            ->toThrow(ValidationException::class);

        expect($this->project->fresh()->agent_channel_id)->toBe($this->channel->id)
            ->and(outboundEvents())->toHaveCount(0);
    });

    it('lets a lead keep a channel they cannot see', function () {
        $secret = makeChannel($this->workspace, ChannelType::Private, ['name' => 'secret-agent']);
        $this->project->forceFill(['agent_channel_id' => $secret->id])->save();
        $this->project->members()->where('user_id', $this->dev->id)->update(['role' => ProjectRole::Lead]);

        app(UpdateProject::class)->handle($this->project, $this->dev, ['agent_channel_id' => $secret->id, 'name' => 'Renamed']);

        expect($this->project->fresh()->only(['agent_channel_id', 'name']))->toBe(['agent_channel_id' => $secret->id, 'name' => 'Renamed']);
    });

    it('refuses both agent columns on one column, against what another save stored meanwhile', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);
        $this->project->forceFill(['agent_review_column_id' => $started->id])->save();

        expect(fn () => app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_in_progress_column_id' => $started->id]))
            ->toThrow(ValidationException::class);
    });

    it('has the database refuse both agent columns on one column too', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);

        expect(fn () => DB::table('projects')->where('id', $this->project->id)->update([
            'agent_in_progress_column_id' => $started->id,
            'agent_review_column_id' => $started->id,
        ]))->toThrow(QueryException::class, 'projects_agent_columns_distinct');
    });
});

describe('settings changed by something other than the form', function () {
    it('clears, reports and audits an agent channel turned into an announcement channel', function () {
        app(UpdateChannel::class)->handle($this->channel, $this->owner, ['type' => ChannelType::Announcement]);

        $audit = AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->sole();

        expect($this->project->fresh()->agent_channel_id)->toBeNull()
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data']['project']['agent']['channel_id'])->toBeNull()
            ->and($audit->user_id)->toBe($this->owner->id)
            ->and($audit->old_values['agent_channel_id'])->toBe($this->channel->id)
            ->and($audit->new_values['agent_channel_id'])->toBeNull();
    });

    it('clears, reports and audits a deleted agent column', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);
        $this->project->forceFill(['agent_in_progress_column_id' => $started->id])->save();
        BoardColumn::factory()->create(['project_id' => $this->project->id, 'name' => 'Doing', 'category' => ColumnCategory::Started, 'position' => 1500]);

        app(DeleteBoardColumn::class)->handle($started, null, $this->owner);

        $audit = AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->sole();

        expect($audit->old_values['agent_in_progress_column_id'])->toBe($started->id)
            ->and($audit->new_values['agent_in_progress_column_id'])->toBeNull()
            ->and($audit->user_id)->toBe($this->owner->id)
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data']['before']['in_progress_column_id'])->toBe($started->id);
    });

    it('clears, reports and audits a deleted agent channel in the same transaction as the delete', function () {
        $statements = statementsDuring(fn () => $this->channel->delete());

        expect($this->project->fresh()->agent_channel_id)->toBeNull()
            ->and(outboundEvents(OutboundEventType::ProjectAgentSettingsChanged)->sole()->payload['data']['before']['channel_id'])->toBe($this->channel->id)
            ->and(AuditLog::query()->where('action', AuditAction::ProjectAgentSettingsUpdated)->count())->toBe(1)
            ->and(firstStatement($statements, 'pg_advisory_xact_lock'))->toBeLessThan(firstStatement($statements, 'delete from "channels"'))
            ->and(firstStatement($statements, 'delete from "channels"'))->toBeLessThan(firstStatement($statements, 'insert into "outbound_events"'));
    });
});

describe('bulk task writes', function () {
    it('locks the project and then the cards in id order before a rebalance', function () {
        $column = projectColumn($this->project, ColumnCategory::Unstarted);
        $a = makeTask($this->project, $this->owner, ['position' => 1000]);
        $b = makeTask($this->project, $this->owner, ['position' => 1001]);
        $moving = makeTask($this->project, $this->owner, ['position' => 5000]);

        $statements = statementsDuring(fn () => app(MoveTask::class)->handle($moving, [
            'board_column_id' => $column->id,
            'before_task_id' => $a->id,
            'after_task_id' => $b->id,
        ]));

        $project = firstStatement($statements, 'for no key update');
        $tasks = firstStatement($statements, 'order by "id" asc for update');

        expect($project)->not->toBeNull()
            ->and($project)->toBeLessThan($tasks)
            ->and($tasks)->toBeLessThan(firstWrite($statements));
    });

    it('locks the project and then the cards in id order before completing a sprint', function () {
        $this->project->forceFill(['uses_sprints' => true])->save();
        $sprint = Sprint::factory()->create(['project_id' => $this->project->id, 'started_at' => now()->subWeek(), 'completed_at' => null]);
        $this->task->forceFill(['sprint_id' => $sprint->id])->save();

        $statements = statementsDuring(fn () => app(CompleteSprint::class)->handle($sprint, $this->owner));

        $project = firstStatement($statements, 'for no key update');
        $tasks = firstStatement($statements, 'order by "id" asc for update');

        expect($project)->not->toBeNull()
            ->and($project)->toBeLessThan($tasks)
            ->and($tasks)->toBeLessThan(firstWrite($statements));
    });
});

describe('scrubbing a deleted message', function () {
    it('scrubs unsent and finished events in its own workspace, and leaves an attempted one as it was sent', function () {
        $root = makeMessage($this->channel, $this->owner, 'Plan');
        $reply = app(SendMessage::class)->handle($this->channel, $this->dev, 'hunter2', $root);

        $unsent = outboundEvents(OutboundEventType::MessageCreated)->sole();
        $attempted = $unsent->replicate()->fill(['event_id' => (string) Str::uuid7(), 'sequence' => 100, 'attempts' => 1]);
        $attempted->save();
        $delivered = $unsent->replicate()->fill(['event_id' => (string) Str::uuid7(), 'sequence' => 101, 'status' => OutboundEventStatus::Delivered, 'attempts' => 1]);
        $delivered->save();

        [$elsewhere] = makeWorkspaceWithOwner();
        $foreign = $unsent->replicate()->fill(['event_id' => (string) Str::uuid7(), 'workspace_id' => $elsewhere->id, 'sequence' => 1]);
        $foreign->save();

        app(DeleteMessage::class)->handle($reply, $this->dev);

        expect($unsent->fresh()->payload['data']['message']['body'])->toBe('')
            ->and($delivered->fresh()->payload['data']['message']['body'])->toBe('')
            ->and($attempted->fresh()->payload['data']['message']['body'])->toBe('hunter2')
            ->and($foreign->fresh()->payload['data']['message']['body'])->toBe('hunter2');
    });
});
