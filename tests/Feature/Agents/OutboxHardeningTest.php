<?php

use App\Actions\Messages\SendMessage;
use App\Enums\ColumnCategory;
use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use App\Enums\ProjectRole;
use App\Models\BoardColumn;
use App\Models\Message;
use App\Models\OutboundEvent;
use App\Models\TaskLabel;
use App\Models\Workspace;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The outbox's guarantees beyond delivery: a gapless per-workspace sequence,
 * a bounded lifetime for what it holds, a safe transport, and a bounded cost
 * on the paths that write to it.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->owner);
    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
});

function recordProbe(TestCase $test, ?Workspace $workspace = null): ?OutboundEvent
{
    return recordOutboundEvent($workspace ?? $test->workspace);
}

describe('the sequence', function () {
    it('counts per workspace, and a rolled-back event leaves no gap', function () {
        [$other] = makeWorkspaceWithOwner();

        recordProbe($this);
        recordProbe($this, $other);

        try {
            $events = app(OutboundEvents::class);
            $events->transaction([$this->workspace->id], function () use ($events) {
                $events->record(OutboundEventType::ProjectAgentSettingsChanged, $this->workspace, ['probe' => true]);

                throw new RuntimeException('the change failed');
            });
        } catch (RuntimeException) {
        }

        recordProbe($this);
        recordProbe($this, $other);

        expect(OutboundEvent::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->pluck('sequence')->all())->toBe([1, 2])
            ->and(OutboundEvent::query()->where('workspace_id', $other->id)->orderBy('id')->pluck('sequence')->all())->toBe([1, 2]);
    });

    it('makes a second writer wait for the first to commit', function () {
        /*
         * A rival session holds the workspace's counter mid-transaction. This
         * one must not be handed a number until that commits or rolls back —
         * which is what makes sequence order equal commit order — so with a
         * short lock timeout it fails instead of numbering past the rival.
         */
        config(['database.connections.outbox_rival' => config('database.connections.'.config('database.default'))]);
        $rival = DB::connection('outbox_rival');

        $ownerId = $rival->table('users')->insertGetId([
            'name' => 'Rival', 'email' => Str::uuid().'@example.test',
            'password' => 'x', 'username' => 'rival-'.Str::lower(Str::random(10)),
        ]);
        $workspaceId = $rival->table('workspaces')->insertGetId([
            'owner_id' => $ownerId, 'name' => 'Rival', 'slug' => 'rival-'.Str::lower(Str::random(10)),
        ]);
        $workspace = Workspace::query()->findOrFail($workspaceId);

        try {
            $rival->beginTransaction();
            $rival->selectOne('INSERT INTO outbound_sequences (workspace_id, value) VALUES (?, 1) RETURNING value', [$workspaceId]);

            $blocked = false;

            try {
                $events = app(OutboundEvents::class);
                $events->transaction([$workspace->id], function () use ($events, $workspace) {
                    // After the transaction's own timeout, which would wait ten seconds.
                    DB::statement("SET LOCAL lock_timeout = '300ms'");
                    $events->record(OutboundEventType::ProjectAgentSettingsChanged, $workspace, ['probe' => true]);
                });
            } catch (QueryException $exception) {
                $blocked = str_contains($exception->getMessage(), 'lock timeout');
            }

            expect($blocked)->toBeTrue()
                ->and(OutboundEvent::query()->where('workspace_id', $workspaceId)->count())->toBe(0);
        } finally {
            $rival->rollBack();
            $rival->table('workspaces')->where('id', $workspaceId)->delete();
            $rival->table('users')->where('id', $ownerId)->delete();
            $rival->disconnect();
        }
    });
});

describe('what the outbox keeps', function () {
    it('prunes delivered events after a week and dead ones after a month', function () {
        $oldDelivered = recordProbe($this)->forceFill(['status' => OutboundEventStatus::Delivered, 'delivered_at' => now()->subDays(8)]);
        $oldDelivered->save();
        $newDelivered = recordProbe($this)->forceFill(['status' => OutboundEventStatus::Delivered, 'delivered_at' => now()->subDays(6)]);
        $newDelivered->save();
        $oldDead = recordProbe($this)->forceFill(['status' => OutboundEventStatus::Dead]);
        $oldDead->save();
        OutboundEvent::query()->whereKey($oldDead->id)->update(['updated_at' => now()->subDays(31)]);
        $newDead = recordProbe($this)->forceFill(['status' => OutboundEventStatus::Dead]);
        $newDead->save();
        $pending = recordProbe($this);
        OutboundEvent::query()->whereKey($pending->id)->update(['created_at' => now()->subDays(40)]);

        $this->artisan('model:prune', ['--model' => [OutboundEvent::class]])->assertSuccessful();

        expect(OutboundEvent::query()->orderBy('id')->pluck('id')->all())
            ->toBe([$newDelivered->id, $newDead->id, $pending->id]);
    });

    it('is pruned daily', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'OutboundEvent'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('45 3 * * *');
    });

    it('drops a deleted reply\'s text from events still waiting to be sent', function () {
        $dev = joinWorkspace($this->workspace);
        addToChannel($this->channel, $dev);
        $root = makeMessage($this->channel, $dev, 'Plan');

        $this->actingAs($dev);

        $reply = Message::query()->findOrFail($this->postJson(route('channels.messages.store', [$this->workspace->slug, 'pca-agent']), [
            'body' => 'my password is hunter2',
            'parent_message_id' => $root->id,
        ])->assertCreated()->json('message.id'));

        $this->patchJson(route('messages.update', [$this->workspace->slug, $reply->id]), ['body' => 'oops, hunter2 again'])->assertOk();

        $unrelated = recordProbe($this);

        $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $reply->id]))->assertSuccessful();

        $created = outboundEvents(OutboundEventType::MessageCreated)->sole();
        $updated = outboundEvents(OutboundEventType::MessageUpdated)->sole();

        expect($created->payload['data']['message']['body'])->toBe('')
            ->and($created->payload['data']['message']['body_plaintext'])->toBe('')
            ->and($created->payload['data']['message']['id'])->toBe($reply->id)
            ->and($updated->payload['data']['message']['body'])->toBe('')
            ->and(json_encode(OutboundEvent::query()->pluck('payload')))->not->toContain('hunter2')
            ->and($unrelated->fresh()->payload)->toEqual($unrelated->payload)
            ->and(outboundEvents(OutboundEventType::MessageDeleted)->sole()->payload['data']['message_id'])->toBe($reply->id);
    });
});

describe('the transport', function () {
    it('refuses a plain-http dispatcher unless told otherwise in so many words, and says so once', function () {
        Log::spy();
        (fn () => self::$warnedInsecure = false)->call(app(OutboundEvents::class));

        // Whatever the environment claims to be — this one is `testing`.
        enableAgentDispatcher('http://dispatcher.test/events');
        $events = app(OutboundEvents::class);

        expect($events->enabled())->toBeFalse()
            ->and($events->enabled())->toBeFalse()
            ->and(recordProbe($this))->toBeNull();

        config(['snack.agents.allow_insecure' => true]);

        expect($events->enabled())->toBeTrue();

        config(['snack.agents.allow_insecure' => false]);
        enableAgentDispatcher('https://dispatcher.test/events');

        expect($events->enabled())->toBeTrue();

        Log::shouldHaveReceived('warning')->once();
    });
});

describe('who sees agent settings', function () {
    it('shows the agent ids to a lead and hides them from a member', function () {
        $member = joinWorkspace($this->workspace);
        addToProject($this->project, $member);

        $this->actingAs($this->owner)
            ->get(route('projects.board', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('project.agent_channel_id', $this->channel->id));

        $this->actingAs($member)
            ->get(route('projects.board', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('project.agent_channel_id')
                ->missing('project.agent_in_progress_column_id')
                ->missing('project.agent_review_column_id'));

        $this->actingAs($member)
            ->get(route('projects.settings.edit', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('project.agent_channel_id')
                ->where('agentChannels', [])
                ->where('columns', []));
    });

    it('will not put both agent columns on the same column', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);
        $this->project->forceFill(['agent_in_progress_column_id' => $started->id])->save();

        $this->actingAs($this->owner)
            ->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['agent_review_column_id' => $started->id])
            ->assertSessionHasErrors('agent_review_column_id');

        $review = BoardColumn::factory()->create(['project_id' => $this->project->id, 'name' => 'In review', 'category' => ColumnCategory::Started, 'position' => 1500]);
        $this->project->forceFill(['agent_review_column_id' => $review->id])->save();

        $this->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['agent_in_progress_column_id' => $review->id])
            ->assertSessionHasErrors('agent_in_progress_column_id');

        $this->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'agent_in_progress_column_id' => $review->id,
            'agent_review_column_id' => $started->id,
        ])->assertSessionHasNoErrors();

        expect($this->project->fresh()->only(['agent_in_progress_column_id', 'agent_review_column_id']))
            ->toBe(['agent_in_progress_column_id' => $review->id, 'agent_review_column_id' => $started->id]);
    });

    it('refuses a connector kind the column does not know, whatever writes it', function () {
        connectAs($this->owner, $this->workspace);

        expect(fn () => DB::table('oauth_access_tokens')->update(['connector_kind' => 'robot']))
            ->toThrow(QueryException::class, 'oauth_access_tokens_connector_kind_check');
    });
});

describe('cost on the hot paths', function () {
    it('costs the same queries to send a reply whether one project or five listen', function () {
        $dev = joinWorkspace($this->workspace);
        addToChannel($this->channel, $dev);
        $root = makeMessage($this->channel, $dev, 'Plan');

        $count = function () use ($dev, $root): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(SendMessage::class)->handle($this->channel, $dev, 'reply', $root);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count();
        $withOne = $count();

        foreach (range(1, 4) as $n) {
            $project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => "Listener {$n}", 'key' => "LS{$n}"]);
            addToProject($project, joinWorkspace($this->workspace), ProjectRole::Lead);
        }

        $withFive = $count();

        expect($withFive)->toBe($withOne)
            ->and(outboundEvents(OutboundEventType::MessageCreated)->last()->payload['data']['projects'])->toHaveCount(5);
    });

    it('locks nothing to recolour a label', function () {
        $label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);
        makeTask($this->project, $this->owner)->labels()->attach($label);

        DB::enableQueryLog();

        $this->actingAs($this->owner)
            ->patch(route('projects.labels.update', [$this->workspace->slug, $this->project->slug, $label->id]), ['color' => 'red'])
            ->assertRedirect();

        $locking = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'for update'));

        expect($locking)->toHaveCount(0)
            ->and($label->fresh()->color)->toBe('red');
    });

    it('locks the workspace, then the project, then the label, then the cards to rename one', function () {
        $label = TaskLabel::factory()->create(['project_id' => $this->project->id, 'name' => 'agent']);
        makeTask($this->project, $this->owner)->labels()->attach($label);

        DB::enableQueryLog();

        $this->actingAs($this->owner)
            ->patch(route('projects.labels.update', [$this->workspace->slug, $this->project->slug, $label->id]), ['name' => 'ai'])
            ->assertRedirect();

        $locked = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => Str::contains(strtolower($query), ['pg_advisory_xact_lock', 'for update', 'for no key update']))
            ->map(fn (string $query): string => str_contains($query, 'pg_advisory_xact_lock')
                ? 'workspace'
                : Str::betweenFirst($query, 'from "', '"').(str_contains($query, 'for no key update') ? ' (no key)' : ''))
            ->values()
            ->all();

        expect($locked)->toBe(['workspace', 'projects (no key)', 'task_labels', 'tasks']);
    });
});
