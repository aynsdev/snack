<?php

use App\Actions\Projects\SaveTask;
use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use App\Jobs\DeliverOutboundEvent;
use App\Models\IncomingWebhook;
use App\Models\OutboundEvent;
use App\Services\Outbound\OutboundDelivery;
use App\Services\Outbound\OutboundEvents;
use App\Services\WebhookSignature;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The outbox: written with the change it describes, delivered signed, retried
 * on a schedule, given up on after a day, and never sent twice at once.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent']);
    $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix the login']);
});

/**
 * Check a request the way the dispatcher must: through Snack's own verifier,
 * so the two can never disagree about what was signed.
 */
function dispatcherVerdict(SentRequest $sent, ?string $secret = null): ?string
{
    $hook = (new IncomingWebhook)->forceFill(['signing_secret' => $secret ?? dispatcherSecret()]);

    $request = Request::create('/events', 'POST', server: [
        'HTTP_X_SNACK_TIMESTAMP' => $sent->header(WebhookSignature::TIMESTAMP_HEADER)[0] ?? '',
        'HTTP_X_SNACK_SIGNATURE' => $sent->header(WebhookSignature::SIGNATURE_HEADER)[0] ?? '',
    ], content: $sent->body());

    return app(WebhookSignature::class)->verify($hook, $request);
}

function assignViaBoard(object $test, int $assigneeId): void
{
    $test->actingAs($test->owner)
        ->patchJson(route('tasks.update', [$test->workspace->slug, $test->task->id]), ['assignee_id' => $assigneeId])
        ->assertOk();
}

describe('recording', function () {
    it('records nothing while the dispatcher is not fully configured', function (?string $url, ?string $secret) {
        config(['snack.agents.dispatcher_url' => $url, 'snack.agents.dispatcher_secret' => $secret]);
        Http::preventStrayRequests();

        assignViaBoard($this, $this->owner->id);

        expect(OutboundEvent::query()->count())->toBe(0);
    })->with([
        'neither' => [null, null],
        'url only' => ['https://dispatcher.test/events', null],
        'secret only' => [null, 'q3J8vN1xR0ZtYwBc5kLmP2sDf9GhUe7Ai4oTn6KbXyE='],
        'blank' => ['', ''],
    ]);

    it('writes nothing, and sends nothing, when the change rolls back', function () {
        enableAgentDispatcher();
        Http::fake();

        try {
            app(OutboundEvents::class)->transaction([$this->workspace->id], function () {
                app(SaveTask::class)->update($this->task, $this->owner, ['assignee_id' => $this->owner->id]);

                throw new RuntimeException('Something later in the request failed.');
            });
        } catch (RuntimeException) {
        }

        expect(OutboundEvent::query()->count())->toBe(0)
            ->and($this->task->fresh()->assignee_id)->toBeNull();

        Http::assertNothingSent();
    });
});

describe('delivering', function () {
    it('sends a signed envelope in the contract shape once the change commits', function () {
        enableAgentDispatcher();
        Http::fake(['dispatcher.test/*' => Http::response(['ok' => true])]);

        assignViaBoard($this, $this->owner->id);

        $event = OutboundEvent::query()->sole();

        expect($event->status)->toBe(OutboundEventStatus::Delivered)
            ->and($event->attempts)->toBe(1)
            ->and($event->last_status)->toBe(200)
            ->and($event->delivered_at)->not->toBeNull();

        Http::assertSentCount(1);

        Http::assertSent(function (SentRequest $request) use ($event): bool {
            $body = json_decode($request->body(), true);

            expect($request->url())->toBe('https://dispatcher.test/events')
                ->and($request->method())->toBe('POST')
                ->and($request->header('Content-Type'))->toBe(['application/json'])
                ->and($request->header('User-Agent'))->toBe(['Snack-Outbound/1'])
                ->and($request->header('X-Snack-Event'))->toBe(['task.eligibility_changed'])
                ->and($request->header(WebhookSignature::SIGNATURE_HEADER)[0])->toStartWith('v1=')
                ->and(dispatcherVerdict($request))->toBeNull()
                ->and(array_keys($body))->toBe([
                    'event_id', 'sequence', 'type', 'schema_version', 'occurred_at',
                    'instance', 'workspace', 'actor', 'data',
                ])
                ->and($body['event_id'])->toBe($event->event_id)
                ->and($body['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
                ->and($body['sequence'])->toBe($event->sequence)
                ->and($body['sequence'])->toBe(1)
                ->and($body['type'])->toBe('task.eligibility_changed')
                ->and($body['schema_version'])->toBe(1)
                ->and($body['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
                ->and($body['instance'])->toBe(config('app.url'))
                ->and($body['workspace'])->toEqual(['id' => $this->workspace->id, 'slug' => $this->workspace->slug])
                ->and($body['actor'])->toEqual([
                    'user_id' => $this->owner->id,
                    'via' => 'web',
                    'connector_id' => null,
                    'is_bot' => false,
                ]);

            return true;
        });
    });

    it('refuses a tampered body and a stale timestamp', function () {
        enableAgentDispatcher();
        Http::fake();

        assignViaBoard($this, $this->owner->id);

        $sent = Http::recorded()[0][0];

        $tampered = new SentRequest(new PsrRequest(
            'POST',
            $sent->url(),
            $sent->headers(),
            str_replace('"task.eligibility_changed"', '"message.created"', $sent->body()),
        ));

        expect(dispatcherVerdict($sent))->toBeNull()
            ->and(dispatcherVerdict($tampered))->toBe(WebhookSignature::INVALID)
            ->and(dispatcherVerdict($sent, 'some-other-secret'))->toBe(WebhookSignature::INVALID);

        $this->travel(301)->seconds();

        expect(dispatcherVerdict($sent))->toBe(WebhookSignature::STALE);
    });

    it('retries a refused delivery with the same body and a fresh signature', function () {
        enableAgentDispatcher();
        Http::fake(['dispatcher.test/*' => Http::sequence()->push('down for maintenance', 503)->push('', 202)]);

        assignViaBoard($this, $this->owner->id);

        $event = OutboundEvent::query()->sole();

        expect($event->status)->toBe(OutboundEventStatus::Pending)
            ->and($event->attempts)->toBe(1)
            ->and($event->last_status)->toBe(503)
            ->and($event->last_error)->toContain('HTTP 503')
            ->and($event->available_at->diffInSeconds(now()->addSeconds(10), absolute: true))->toBeLessThan(2);

        // Not due yet: nothing goes out early.
        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeFalse();
        Http::assertSentCount(1);

        $this->travel(11)->seconds();

        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeTrue();

        [$first, $second] = [Http::recorded()[0][0], Http::recorded()[1][0]];

        expect($second->body())->toBe($first->body())
            ->and($second->header(WebhookSignature::TIMESTAMP_HEADER))->not->toBe($first->header(WebhookSignature::TIMESTAMP_HEADER))
            ->and(dispatcherVerdict($second))->toBeNull()
            ->and($event->fresh()->status)->toBe(OutboundEventStatus::Delivered)
            ->and($event->fresh()->attempts)->toBe(2)
            ->and($event->fresh()->last_error)->toBeNull();
    });

    it('treats a connection failure and a redirect as failures', function () {
        enableAgentDispatcher();
        Http::fake(['dispatcher.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->push('', 302, ['Location' => 'https://elsewhere.test/'])]);

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        expect($event->fresh()->status)->toBe(OutboundEventStatus::Pending)
            ->and($event->fresh()->last_status)->toBeNull()
            ->and($event->fresh()->last_error)->not->toBeEmpty();

        $this->travel(11)->seconds();
        app(OutboundDelivery::class)->attempt($event->id);

        expect($event->fresh()->status)->toBe(OutboundEventStatus::Pending)
            ->and($event->fresh()->last_status)->toBe(302);
    });

    it('backs off on the contract schedule', function () {
        expect(array_map(OutboundDelivery::backoff(...), range(1, 9)))
            ->toBe([10, 30, 120, 600, 1800, 3600, 3600, 3600, 3600]);
    });

    it('gives an event up as dead once it has failed for a day', function () {
        enableAgentDispatcher();
        Http::fake(['dispatcher.test/*' => Http::response('nope', 500)]);

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        $this->travel(23)->hours();
        app(OutboundDelivery::class)->attempt($event->id);

        expect($event->fresh()->status)->toBe(OutboundEventStatus::Pending);

        $this->travel(2)->hours();
        app(OutboundDelivery::class)->attempt($event->id);

        // Past the window it is marked dead on claim, without one more send.
        expect($event->fresh()->status)->toBe(OutboundEventStatus::Dead)
            ->and($event->fresh()->attempts)->toBe(2)
            ->and($event->fresh()->last_status)->toBe(500)
            ->and($event->fresh()->last_error)->toBe('Expired before it could be delivered.');

        // Dead stays dead: neither a worker nor the sweeper picks it up again.
        $this->travel(2)->hours();
        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        Http::assertSentCount(2);
    });

    it('does not send an event once at the end of a day-long outage', function () {
        enableAgentDispatcher();
        Queue::fake();
        Http::fake();

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        $this->travel(25)->hours();

        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeFalse()
            ->and($event->fresh()->status)->toBe(OutboundEventStatus::Dead)
            ->and($event->fresh()->attempts)->toBe(0);

        Http::assertNothingSent();
    });

    it('stores neither the URL\'s credentials nor what the receiver said', function () {
        enableAgentDispatcher('https://user:hunter2@dispatcher.test/events?key=abc');
        Http::fake([
            'dispatcher.test/*' => Http::sequence()
                ->push('internal stack trace with secrets', 500)
                ->pushFailedConnection(),
        ]);

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        expect($event->fresh()->last_error)->toBe('HTTP 500 from dispatcher.test');

        $this->travel(11)->seconds();
        app(OutboundDelivery::class)->attempt($event->id);

        expect($event->fresh()->last_error)->toBe(ConnectionException::class.' contacting dispatcher.test')
            ->and($event->fresh()->last_error)->not->toContain('hunter2')
            ->and($event->fresh()->last_error)->not->toContain('abc');
    });

    it('gives up connecting after three seconds and on the whole request after ten', function () {
        enableAgentDispatcher();
        $options = null;

        Http::fake(function (SentRequest $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response();
        });

        recordOutboundEvent($this->workspace, ['probe' => true]);

        expect($options['connect_timeout'])->toBe(3)
            ->and($options['timeout'])->toBe(10)
            ->and($options['allow_redirects'])->toBeFalse();
    });

    it('queues deliveries on their own queue', function () {
        enableAgentDispatcher();
        Queue::fake();

        recordOutboundEvent($this->workspace, ['probe' => true]);

        Queue::assertPushedOn('outbound', DeliverOutboundEvent::class);
    });
});

describe('never twice at once', function () {
    it('does not send an event again once it has been delivered', function () {
        enableAgentDispatcher();
        Http::fake();

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeFalse();
        DeliverOutboundEvent::dispatchSync($event->id);

        Http::assertSentCount(1);
    });

    it('leaves a row alone while another worker holds its lease', function () {
        enableAgentDispatcher();
        Queue::fake();
        Http::fake();

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        // What a claim leaves behind: a lease into the future.
        $event->forceFill(['attempts' => 1, 'available_at' => now()->addSeconds(OutboundDelivery::LEASE_SECONDS)])->save();

        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeFalse();
        Http::assertNothingSent();

        // A worker that died mid-send: the lease runs out and the row is sent.
        $this->travel(OutboundDelivery::LEASE_SECONDS + 1)->seconds();

        expect(app(OutboundDelivery::class)->attempt($event->id))->toBeTrue();
        Http::assertSentCount(1);
    });

    it('skips a row that another connection has locked rather than waiting for it', function () {
        enableAgentDispatcher();
        Http::fake();

        /*
         * A second, real connection, because a row lock only means anything
         * between two sessions — and the row has to be committed for the other
         * session to see it at all, so it is written and removed by hand here
         * rather than inside the test's own transaction.
         */
        config(['database.connections.outbox_rival' => config('database.connections.'.config('database.default'))]);
        $rival = DB::connection('outbox_rival');

        // Committed through the rival, since this session's rows are invisible
        // to it until the test's own transaction ends — which it never does.
        $ownerId = $rival->table('users')->insertGetId([
            'name' => 'Rival', 'email' => Str::uuid().'@example.test',
            'password' => 'x', 'username' => 'rival-'.Str::lower(Str::random(10)),
        ]);
        $workspaceId = $rival->table('workspaces')->insertGetId([
            'owner_id' => $ownerId, 'name' => 'Rival', 'slug' => 'rival-'.Str::lower(Str::random(10)),
        ]);

        $id = $rival->table('outbound_events')->insertGetId([
            'event_id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'sequence' => 1,
            'type' => OutboundEventType::ProjectAgentSettingsChanged->value,
            'payload' => json_encode(['schema_version' => 1, 'data' => ['probe' => true]]),
            'status' => OutboundEventStatus::Pending->value,
            'attempts' => 0,
            'available_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $rival->beginTransaction();
            $rival->table('outbound_events')->where('id', $id)->lockForUpdate()->first();

            // Visible to this session and due — only the lock stands in the way.
            expect(OutboundEvent::query()->whereKey($id)->due()->exists())->toBeTrue();

            $started = microtime(true);
            $sent = app(OutboundDelivery::class)->attempt($id);

            expect($sent)->toBeFalse()
                ->and(microtime(true) - $started)->toBeLessThan(2.0);

            Http::assertNothingSent();
        } finally {
            $rival->rollBack();
            $rival->table('workspaces')->where('id', $workspaceId)->delete();
            $rival->table('users')->where('id', $ownerId)->delete();
            $rival->disconnect();
        }
    });
});

describe('the sweeper', function () {
    it('delivers a committed row whose job never ran', function () {
        enableAgentDispatcher();
        Http::fake();

        // Written without its job, as if the process died right after commit.
        $orphan = OutboundEvent::create([
            'event_id' => (string) Str::uuid7(),
            'workspace_id' => $this->workspace->id,
            'sequence' => 1,
            'type' => OutboundEventType::ProjectAgentSettingsChanged,
            'payload' => ['schema_version' => 1, 'data' => ['probe' => true]],
            'status' => OutboundEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);
        $later = OutboundEvent::create([
            ...$orphan->only(['workspace_id', 'type', 'payload', 'status', 'attempts']),
            'event_id' => (string) Str::uuid7(),
            'sequence' => 2,
            'available_at' => now()->addMinutes(5),
        ]);

        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        expect($orphan->fresh()->status)->toBe(OutboundEventStatus::Delivered)
            ->and($later->fresh()->status)->toBe(OutboundEventStatus::Pending)
            ->and($later->fresh()->attempts)->toBe(0);

        Http::assertSentCount(1);
    });

    it('does not stack a second job behind one still waiting in the queue', function () {
        enableAgentDispatcher();
        Queue::fake();

        $event = recordOutboundEvent($this->workspace, ['probe' => true]);

        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();
        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        Queue::assertPushed(DeliverOutboundEvent::class, 1);
        Queue::assertPushed(DeliverOutboundEvent::class, fn (DeliverOutboundEvent $job): bool => $job->eventId === $event->id);
    });

    it('does nothing while the dispatcher is not configured', function () {
        Queue::fake();

        OutboundEvent::create([
            'event_id' => (string) Str::uuid7(),
            'workspace_id' => $this->workspace->id,
            'sequence' => 1,
            'type' => OutboundEventType::ProjectAgentSettingsChanged,
            'payload' => ['schema_version' => 1, 'data' => []],
            'status' => OutboundEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $this->artisan('snack:sweep-outbound-events')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('is scheduled every minute', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'snack:sweep-outbound-events'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *');
    });
});

it('indexes only the pending rows the sweeper asks about', function () {
    $index = collect(Schema::getIndexes('outbound_events'))->firstWhere('name', 'outbound_events_due_index');

    $definition = DB::selectOne("select indexdef from pg_indexes where indexname = 'outbound_events_due_index'")->indexdef;

    expect($index['columns'])->toBe(['available_at'])
        ->and($definition)->toContain("WHERE ((status)::text = 'pending'::text)");
});
