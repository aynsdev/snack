<?php

use App\Actions\Channels\AddChannelMembers;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Actions\Channels\UpdateChannel;
use App\Actions\Connectors\DisconnectConnector;
use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\SendMessage;
use App\Actions\Messages\UpdateMessage;
use App\Actions\Profile\DeleteAccount;
use App\Actions\Projects\SaveTask;
use App\Actions\Projects\UpdateProject;
use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetWorkspaceMemberStatus;
use App\Actions\Workspaces\UpdateWorkspaceMemberRole;
use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Enums\ColumnCategory;
use App\Enums\ConnectorKind;
use App\Enums\MemberStatus;
use App\Enums\OutboundEventType;
use App\Enums\ProjectRole;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverOutboundEvent;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Message;
use App\Models\OAuthToken;
use App\Models\OutboundEvent;
use App\Models\TaskLabel;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Review cycle 3: what a change reports must match what it committed — under
 * contention, after a concurrent change, and when the same request arrives
 * twice.
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
 * A second session holding this workspace's outbound lock, as a concurrent
 * change would. Release it with {@see releaseRival()}.
 */
function rivalHoldingWorkspace(int $workspaceId): Connection
{
    config(['database.connections.outbox_rival' => config('database.connections.'.config('database.default'))]);
    config(['snack.agents.lock_timeout' => '300ms']);

    $rival = DB::connection('outbox_rival');
    $rival->beginTransaction();
    $rival->select('SELECT pg_advisory_xact_lock(?, ?)', [OutboundEvents::LOCK_NAMESPACE, $workspaceId]);

    return $rival;
}

function releaseRival(Connection $rival): void
{
    $rival->rollBack();
    $rival->disconnect();
}

/**
 * @return array<int, string>
 */
function loggedStatements(Closure $callback): array
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
 * @param  array<int, string>  $statements
 */
function positionOf(array $statements, string $needle): ?int
{
    foreach ($statements as $index => $statement) {
        if (str_contains($statement, $needle)) {
            return $index;
        }
    }

    return null;
}

describe('C3-1 the actor is authorized again under the lock', function () {
    it('refuses a lead whose membership was suspended after their request loaded it', function () {
        $public = makeChannel($this->workspace, attributes: ['name' => 'agents-public']);
        $this->project->members()->where('user_id', $this->dev->id)->update(['role' => ProjectRole::Lead]);
        $this->dev->load('workspaceMemberships');

        WorkspaceMember::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->dev->id)
            ->update(['status' => MemberStatus::Suspended]);

        expect(fn () => app(UpdateProject::class)->handle($this->project, $this->dev, ['agent_channel_id' => $public->id]))
            ->toThrow(AuthorizationException::class);

        expect($this->project->fresh()->agent_channel_id)->toBe($this->channel->id);
    });
});

describe('C3-2 deleted text is never exported', function () {
    it('does not write or export an edit to a message deleted since it was loaded', function () {
        $root = makeMessage($this->channel, $this->owner, 'Plan');
        $reply = app(SendMessage::class)->handle($this->channel, $this->dev, 'first', $root);
        $stale = Message::query()->findOrFail($reply->id);

        app(DeleteMessage::class)->handle(Message::query()->findOrFail($reply->id), $this->dev);
        app(UpdateMessage::class)->handle($stale, 'my password is hunter2');

        $row = Message::withTrashed()->findOrFail($reply->id);

        expect($row->body)->toBe('first')
            ->and($row->trashed())->toBeTrue()
            ->and(outboundEvents(OutboundEventType::MessageUpdated))->toHaveCount(0)
            ->and(json_encode(OutboundEvent::query()->pluck('payload')))->not->toContain('hunter2');
    });

    it('deletes a message once however often the request arrives', function () {
        $plan = makeMessage($this->channel, $this->dev, 'Plan');
        $plan->forceFill(['metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]])->save();
        $this->channel->forceFill(['message_count' => 5])->save();

        $first = Message::query()->findOrFail($plan->id);
        $second = Message::query()->findOrFail($plan->id);

        app(DeleteMessage::class)->handle($first, $this->dev);
        app(DeleteMessage::class)->handle($second, $this->dev);

        expect(outboundEvents(OutboundEventType::MessageDeleted))->toHaveCount(1)
            ->and($this->channel->fresh()->message_count)->toBe(4);
    });

    it('exports no text for a message created and deleted in the same transaction', function () {
        $root = makeMessage($this->channel, $this->owner, 'Plan');
        $events = app(OutboundEvents::class);

        $events->transaction([$this->workspace->id], function () use ($root) {
            $reply = app(SendMessage::class)->handle($this->channel, $this->dev, 'hunter2', $root);
            app(DeleteMessage::class)->handle($reply, $this->dev);
        });

        expect(json_encode(OutboundEvent::query()->pluck('payload')))->not->toContain('hunter2')
            ->and(outboundEvents(OutboundEventType::MessageDeleted))->toHaveCount(1);
    });
});

describe('C3-3 a lock lost to a rolled-back savepoint is forgotten', function () {
    it('forgets a workspace locked by a savepoint whose next lock timed out', function () {
        [$second] = makeWorkspaceWithOwner();
        [$third] = makeWorkspaceWithOwner();
        $events = app(OutboundEvents::class);
        $rival = rivalHoldingWorkspace($third->id);
        $seen = [];

        try {
            $events->transaction([$this->workspace->id], function () use ($events, $second, $third, &$seen) {
                try {
                    $events->transaction([$second->id, $third->id], fn () => null);
                } catch (QueryException) {
                }

                $seen = [$events->locked($this->workspace->id), $events->locked($second->id)];
            });
        } finally {
            releaseRival($rival);
        }

        expect($seen)->toBe([true, false]);
    });
});

describe('C3-4 an outbound transaction must be the outermost one', function () {
    it('refuses to start inside a transaction it does not own', function () {
        expect(fn () => DB::transaction(fn () => recordOutboundEvent($this->workspace)))
            ->toThrow(LogicException::class);
    });

    it('lets a person accept an invitation into a workspace whose default channel agents use', function () {
        $this->channel->forceFill(['is_default' => true, 'type' => ChannelType::Public])->save();
        $invitee = User::factory()->create();
        $invitation = WorkspaceInvitation::factory()
            ->forEmail($invitee->email)
            ->create(['workspace_id' => $this->workspace->id, 'invited_by' => $this->owner->id]);

        app(AcceptWorkspaceInvitation::class)->handle($invitation, $invitee);

        expect($this->channel->members()->where('user_id', $invitee->id)->exists())->toBeTrue();
    });
});

describe('C3-5 account deletion', function () {
    it('locks a workspace joined while it waited before writing anything', function () {
        [$late] = makeWorkspaceWithOwner();
        $injected = false;

        DB::listen(function ($query) use (&$injected, $late) {
            if (! $injected && str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $injected = true;
                joinWorkspace($late, $this->dev);
            }
        });

        $statements = loggedStatements(fn () => app(DeleteAccount::class)->handle($this->dev));

        $locks = collect($statements)->keys()->filter(fn (int $i): bool => str_contains($statements[$i], 'pg_advisory_xact_lock'));

        // Writes by the deletion itself: the membership written while it
        // waited lands just before its first lock.
        $firstWrite = collect($statements)->keys()->first(fn (int $i): bool => $i > $locks->first()
            && preg_match('/^(insert|update|delete)\b/', $statements[$i]) === 1);

        expect($locks->last())->toBeLessThan($firstWrite)
            ->and(outboundEvents(OutboundEventType::WorkspaceMemberRemoved)->pluck('workspace_id')->all())->toContain($late->id);
    });

    it('hands back the tasks and messages they authored before the account goes', function () {
        makeTask($this->project, $this->dev, ['title' => 'Reported by them']);
        makeMessage($this->channel, $this->dev, 'said by them');

        $statements = loggedStatements(fn () => app(DeleteAccount::class)->handle($this->dev));

        $reporters = positionOf($statements, 'set "reporter_id" = ?');
        $senders = positionOf($statements, 'set "sender_id" = ?');
        $delete = positionOf($statements, 'delete from "users"');

        expect($reporters)->not->toBeNull()
            ->and($senders)->not->toBeNull()
            ->and($reporters)->toBeLessThan($delete)
            ->and($senders)->toBeLessThan($delete);
    });

    it('does not sign somebody out when their account could not be deleted', function () {
        $this->mock(DeleteAccount::class)->shouldReceive('handle')->andThrow(new RuntimeException('database went away'));

        $this->actingAs($this->dev)->delete(route('profile.destroy'), ['password' => 'password']);

        $this->assertAuthenticatedAs($this->dev);
    });
});

describe('C3-6 epic edits take locks in the shared order', function () {
    it('locks the project, then the task and its epic in id order, before writing', function () {
        $epic = makeTask($this->project, $this->owner, ['type' => TaskType::Epic, 'title' => 'Login']);

        $statements = loggedStatements(fn () => app(SaveTask::class)->update($this->task, $this->owner, ['parent_task_id' => $epic->id]));

        $project = positionOf($statements, 'for no key update');
        $tasks = positionOf($statements, 'order by "id" asc for update');
        $write = collect($statements)->keys()->first(fn (int $i): bool => str_starts_with($statements[$i], 'update "tasks"'));

        expect($project)->not->toBeNull()
            ->and($project)->toBeLessThan($tasks)
            ->and($tasks)->toBeLessThan($write);
    });
});

describe('C3-7 channel edits apply to the channel as committed', function () {
    it('audits the name another edit gave the channel meanwhile', function () {
        $stale = Channel::query()->findOrFail($this->channel->id);
        Channel::query()->whereKey($this->channel->id)->update(['name' => 'renamed-meanwhile', 'slug' => 'renamed-meanwhile']);

        app(UpdateChannel::class)->handle($stale, $this->owner, ['type' => ChannelType::Public]);

        $audit = AuditLog::query()->where('action', AuditAction::ChannelUpdated)->latest('id')->firstOrFail();

        expect($audit->old_values['name'])->toBe('renamed-meanwhile')
            ->and($this->channel->fresh()->name)->toBe('renamed-meanwhile')
            ->and($stale->name)->toBe('renamed-meanwhile');
    });
});

describe('C3-8 agent column ids', function () {
    it('refuses one column for both, however the id was typed', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);
        $this->project->forceFill(['agent_review_column_id' => $started->id])->save();

        expect(fn () => app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_in_progress_column_id' => (string) $started->id]))
            ->toThrow(ValidationException::class);
    });

    it('takes only whole numbers for the columns', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);

        $this->actingAs($this->owner)
            ->patchJson(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['agent_in_progress_column_id' => (string) $started->id])
            ->assertJsonValidationErrors('agent_in_progress_column_id');
    });
});

describe('C3-9 contention answers "try again"', function () {
    it('answers a JSON request with 503 and Retry-After', function () {
        $rival = rivalHoldingWorkspace($this->workspace->id);

        try {
            $this->actingAs($this->owner)
                ->patchJson(route('tasks.update', [$this->workspace->slug, $this->task->id]), ['title' => 'Busy'])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '2')
                ->assertJsonPath('message', 'Snack is busy — please try again.');
        } finally {
            releaseRival($rival);
        }
    });

    it('sends a page back with a flash rather than an error screen', function () {
        $rival = rivalHoldingWorkspace($this->workspace->id);
        $board = route('projects.board', [$this->workspace->slug, $this->project->slug]);

        try {
            $this->actingAs($this->owner)
                ->from($board)
                ->patch(route('tasks.update', [$this->workspace->slug, $this->task->id]), ['title' => 'Busy'])
                ->assertRedirect($board)
                ->assertHeader('Retry-After', '2')
                ->assertSessionHas('error', 'Snack is busy — please try again.');
        } finally {
            releaseRival($rival);
        }
    });

    it('answers an MCP tool call with an error the client can retry', function () {
        [$user] = connectAs($this->owner, $this->workspace);
        $rival = rivalHoldingWorkspace($this->workspace->id);

        try {
            SnackServer::actingAs($user)
                ->tool(UpdateTaskTool::class, ['task' => $this->task->reference(), 'title' => 'Busy'])
                ->assertHasErrors(['Snack is busy — please try again in a moment. Nothing was changed.']);
        } finally {
            releaseRival($rival);
        }
    });
});

describe('C3-10 less work under the lock', function () {
    it('numbers and writes a flush\'s events in one statement each', function () {
        $events = app(OutboundEvents::class);

        $statements = loggedStatements(fn () => $events->transaction([$this->workspace->id], function () use ($events) {
            foreach ([1, 2, 3] as $n) {
                $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['n' => $n]);
            }
        }));

        expect(collect($statements)->filter(fn (string $s): bool => str_contains($s, 'outbound_sequences'))->count())->toBe(1)
            ->and(collect($statements)->filter(fn (string $s): bool => str_starts_with($s, 'insert into "outbound_events"'))->count())->toBe(1)
            ->and(outboundEvents()->map(fn (OutboundEvent $event): array => [$event->sequence, $event->payload['data']['n']])->all())
            ->toBe([[1, 1], [2, 2], [3, 3]]);
    });

    it('looks each assignee up once for a change that cannot alter who sees the channel', function () {
        $label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);
        foreach (range(1, 3) as $n) {
            makeTask($this->project, $this->owner, ['assignee_id' => $this->dev->id])->labels()->attach($label);
        }

        $statements = loggedStatements(fn () => $this->actingAs($this->owner)
            ->patch(route('projects.labels.update', [$this->workspace->slug, $this->project->slug, $label->id]), ['name' => 'ai']));

        expect(collect($statements)->filter(fn (string $s): bool => str_contains($s, 'from "users" where "users"."id" ='))->count())->toBe(1)
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged))->toHaveCount(3);
    });

    it('locks no tasks when somebody leaves a public channel they can still read', function () {
        $this->channel->forceFill(['type' => ChannelType::Public])->save();

        $statements = loggedStatements(fn () => app(LeaveChannel::class)->handle($this->channel, $this->dev));

        expect(positionOf($statements, 'from "tasks"'))->toBeNull();
    });

    it('locks no tasks for a role change that cannot change what anyone sees', function () {
        $membership = WorkspaceMember::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->dev->id)->firstOrFail();

        $statements = loggedStatements(fn () => app(UpdateWorkspaceMemberRole::class)->handle($membership, WorkspaceRole::Admin, $this->owner));

        expect(positionOf($statements, 'from "tasks"'))->toBeNull();
    });
});

describe('C3-16 gaining sight of the agent channel', function () {
    it('reports an assignee added to the private agent channel', function () {
        $outsider = joinWorkspace($this->workspace);
        addToProject($this->project, $outsider);
        $theirs = makeTask($this->project, $this->owner, ['assignee_id' => $outsider->id]);

        app(AddChannelMembers::class)->handle($this->channel, $this->owner, collect([$outsider]));

        $data = outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole()->payload['data'];

        expect($data['task_id'])->toBe($theirs->id)
            ->and($data['before']['assignee_can_view_agent_channel'])->toBeFalse()
            ->and($data['after']['assignee_can_view_agent_channel'])->toBeTrue();
    });

    it('reports an assignee joining the private agent channel', function () {
        $outsider = joinWorkspace($this->workspace);
        addToProject($this->project, $outsider);
        makeTask($this->project, $this->owner, ['assignee_id' => $outsider->id]);

        app(JoinChannel::class)->handle($this->channel, $outsider);

        expect(outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole()->payload['data']['after']['assignee_can_view_agent_channel'])->toBeTrue();
    });
});

describe('C3-17 one order for agent-channel changes', function () {
    it('reports a channel switch before the tasks it affects', function () {
        $public = makeChannel($this->workspace, attributes: ['name' => 'agents-public']);
        $outsider = joinWorkspace($this->workspace);
        addToProject($this->project, $outsider);
        makeTask($this->project, $this->owner, ['assignee_id' => $outsider->id]);

        app(UpdateProject::class)->handle($this->project, $this->owner, ['agent_channel_id' => $public->id]);

        expect(outboundEvents()->pluck('type')->map->value->all())
            ->toBe(['project.agent_settings_changed', 'task.eligibility_changed']);
    });
});

describe('C3-19 a repeated request reports once', function () {
    it('removes a member once', function () {
        $first = WorkspaceMember::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->dev->id)->firstOrFail();
        $second = WorkspaceMember::query()->findOrFail($first->id);

        app(RemoveWorkspaceMember::class)->handle($first, $this->owner);
        app(RemoveWorkspaceMember::class)->handle($second, $this->owner);

        expect(outboundEvents(OutboundEventType::WorkspaceMemberRemoved))->toHaveCount(1)
            ->and(AuditLog::query()->where('action', AuditAction::MemberRemoved)->count())->toBe(1);
    });

    it('suspends a member once', function () {
        $first = WorkspaceMember::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->dev->id)->firstOrFail();
        $second = WorkspaceMember::query()->findOrFail($first->id);

        app(SetWorkspaceMemberStatus::class)->handle($first, MemberStatus::Suspended, $this->owner);
        app(SetWorkspaceMemberStatus::class)->handle($second, MemberStatus::Suspended, $this->owner);

        expect(outboundEvents(OutboundEventType::WorkspaceMemberRemoved))->toHaveCount(1)
            ->and(AuditLog::query()->where('action', AuditAction::MemberSuspended)->count())->toBe(1);
    });

    it('revokes an agent connector once', function () {
        connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
        $first = OAuthToken::query()->where('user_id', $this->dev->id)->latest()->firstOrFail();
        $second = OAuthToken::query()->findOrFail($first->id);

        app(DisconnectConnector::class)->token($this->workspace, $first, $this->dev);
        app(DisconnectConnector::class)->token($this->workspace, $second, $this->dev);

        expect(outboundEvents(OutboundEventType::AgentConnectorRevoked))->toHaveCount(1)
            ->and(AuditLog::query()->where('action', AuditAction::ConnectorRevoked)->count())->toBe(1);
    });
});

describe('C3-20 a queue that refuses a job', function () {
    it('keeps the committed change and reports the failure, leaving the row for the sweeper', function () {
        Exceptions::fake();
        app()->forgetInstance('queue');
        Queue::clearResolvedInstances();
        app('queue')->extend('refusing', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config): SyncQueue
            {
                return new class extends SyncQueue
                {
                    public function push($job, $data = '', $queue = null): mixed
                    {
                        throw new RuntimeException('queue is down');
                    }
                };
            }
        });
        config(['queue.default' => 'refusing', 'queue.connections.refusing' => ['driver' => 'refusing']]);

        $event = recordOutboundEvent($this->workspace);

        expect($event)->not->toBeNull()
            ->and($event->dispatched_at)->toBeNull();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'queue is down');
    });
});

describe('C3-21 the sweeper does not pile up copies', function () {
    it('re-queues a row only once its last dispatch is a quarter of an hour old', function () {
        $event = recordOutboundEvent($this->workspace);
        Queue::fake();

        $this->travel(6)->minutes();
        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        Queue::assertNotPushed(DeliverOutboundEvent::class);

        $this->travel(10)->minutes();
        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        Queue::assertPushed(DeliverOutboundEvent::class, 1);
        expect($event->fresh()->dispatched_at)->not->toBeNull();
    });
});

describe('C3-22 indexes', function () {
    it('indexes the agent column settings', function () {
        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'projects'"))->pluck('indexname');

        expect($indexes)->toContain('projects_agent_in_progress_column_id_index')
            ->toContain('projects_agent_review_column_id_index');
    });
});
