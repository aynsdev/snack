<?php

namespace App\Services\Outbound;

use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use App\Jobs\DeliverOutboundEvent;
use App\Models\OutboundEvent;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\ActorContext;
use App\Support\SharedSecret;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use stdClass;
use Throwable;

/**
 * Records events for the agent dispatcher in the outbox.
 *
 * # Why events are written inside the change's own transaction
 *
 * The row is the event. Written in the same transaction as the change it
 * describes, it commits with the change or not at all — so the dispatcher can
 * never hear about an assignment that rolled back, and never miss one that
 * committed because the process died a moment later. The delivery job is
 * queued after commit, and the sweeper picks up anything that job never got
 * to — see App\Console\Commands\SweepOutboundEvents.
 *
 * # Lock order
 *
 * Every change that may report runs through {@see transaction()}, and so takes
 * the same locks in the same order:
 *
 * 1. the workspace's advisory lock, before anything is written — several
 *    workspaces in ascending id order;
 * 2. the project row (`FOR NO KEY UPDATE`), then whatever the change locks of
 *    its own (a label), then tasks in id order;
 * 3. last of all, at flush, the workspace's sequence counter row.
 *
 * Two reporting changes in one workspace therefore queue on step 1 rather than
 * meeting halfway through each other's rows, and the counter — held until
 * commit — is never waited for by a transaction holding anything else the
 * holder needs.
 *
 * # Why events are buffered and written last
 *
 * {@see record()} and {@see defer()} only note what to report. The rows are
 * built and written by the outermost transaction() as its final statements, from
 * the state the change left behind, so a payload can never describe a model the
 * request loaded before it took its locks. An event noted inside a savepoint
 * that rolls back is dropped with it.
 *
 * Events are numbered in the order they were noted, and every change notes
 * them in the same order: a project's settings first, then its tasks'
 * eligibility, then a task's content — so the receiver always knows where a
 * project's agents talk before it hears which tasks can be worked there.
 *
 * # Why the sequence is a locked counter
 *
 * The receiver applies a workspace's events strictly in `sequence` order, so
 * the number must follow commit order and never skip. Taking it from a counter
 * row that stays locked until this transaction commits gives both: a second
 * writer waits for the first to commit or roll back before it can number its
 * own event. See the `create_outbound_events_table` migration.
 *
 * # The accepted gap
 *
 * Paths that write often (task edits, messages) take the lock only when an
 * unlocked read says the project or channel is reported. A write whose read saw
 * agents off a moment before a lead switched them on is not exported; the
 * settings event that switched them on is the receiver's starting point.
 */
class OutboundEvents
{
    public const SCHEMA_VERSION = 1;

    /**
     * The first key of the two-int advisory lock taken per workspace; the
     * second is the workspace id. Arbitrary, but fixed, so no other advisory
     * lock in Snack shares its key space.
     */
    public const LOCK_NAMESPACE = 1_397_640_001;

    /**
     * Whether the insecure-URL warning has been logged by this process, so a
     * misconfiguration is said once rather than on every message sent.
     */
    private static bool $warnedInsecure = false;

    /** Likewise for a secret too weak to sign with. */
    private static bool $warnedWeakSecret = false;

    /** How many {@see transaction()} calls are open, innermost included. */
    private int $depth = 0;

    /** @var array<int, true> workspace ids whose lock this transaction holds */
    private array $locked = [];

    /** @var array<int, array{type: OutboundEventType, workspace_id: int, actor: array<string, mixed>, occurred_at: string|null, build: Closure(): array<int, array<string, mixed>>}> */
    private array $pending = [];

    private int $nextKey = 0;

    public function __construct(private readonly ActorContext $actors) {}

    /**
     * Whether this installation sends events at all.
     *
     * Both settings are needed — a URL with no secret could only send unsigned
     * requests — and the URL must be https unless plain http was allowed
     * explicitly: the body carries private messages and the signature only
     * proves who sent it. The secret must be one the dispatcher would accept,
     * see {@see SharedSecret}: a guessable one would let anyone sign events.
     */
    public function enabled(): bool
    {
        $url = config('snack.agents.dispatcher_url');

        $secret = config('snack.agents.dispatcher_secret');

        if (! filled($url) || ! filled($secret)) {
            return false;
        }

        $problem = SharedSecret::problem((string) $secret);

        if ($problem !== null) {
            if (! self::$warnedWeakSecret) {
                self::$warnedWeakSecret = true;

                Log::warning("Agent dispatcher events are disabled: SNACK_AGENT_DISPATCHER_SECRET is too weak ({$problem}). Generate one with `openssl rand -base64 32`.");
            }

            return false;
        }

        if (str_starts_with(strtolower((string) $url), 'https://') || config('snack.agents.allow_insecure') === true) {
            return true;
        }

        if (! self::$warnedInsecure) {
            self::$warnedInsecure = true;

            Log::warning('Agent dispatcher events are disabled: SNACK_AGENT_DISPATCHER_URL must use https, or SNACK_AGENT_ALLOW_INSECURE must be set.');
        }

        return false;
    }

    /**
     * Whether changes to this project are reported: the installation sends
     * events, and the project's agents are switched on.
     */
    public function watching(Project $project): bool
    {
        return $this->enabled() && $project->agentsEnabled();
    }

    /**
     * Run a change that may report to the dispatcher.
     *
     * Takes the workspaces' locks before the callback writes anything, and
     * writes every event the callback noted as the last thing it does. Nested
     * calls share the outermost one's buffer; a nested call naming a workspace
     * not yet locked locks it then, which is out of order — only account
     * deletion spans workspaces, and it locks all of them up front.
     *
     * The outermost call must also be the outermost database transaction.
     * Flushing inside somebody else's transaction would write the events
     * before that transaction's own later writes, and take the lock after its
     * earlier ones — so it is refused rather than accommodated. Callers that
     * need a transaction of their own open it through here instead.
     *
     * @template T
     *
     * @param  array<int, int>  $workspaceIds
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(array $workspaceIds, Closure $callback): mixed
    {
        if ($this->depth === 0 && $this->enabled() && $this->insideAnotherTransaction()) {
            throw new LogicException('OutboundEvents::transaction() must be the outermost transaction: its events are written as that transaction\'s last statements, and the workspace lock must come before its first write.');
        }

        return DB::transaction(function () use ($workspaceIds, $callback): mixed {
            $outermost = $this->depth === 0;
            $this->depth++;

            try {
                $this->lock($workspaceIds);

                $result = $callback();

                if ($outermost) {
                    $this->flush();
                }

                return $result;
            } finally {
                $this->depth--;

                if ($outermost) {
                    $this->pending = [];
                    $this->locked = [];
                }
            }
        });
    }

    /**
     * Whether the current transaction holds this workspace's lock, which is
     * what entitles it to report on the workspace.
     */
    public function locked(int $workspaceId): bool
    {
        return isset($this->locked[$workspaceId]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(OutboundEventType $type, Workspace $workspace, array $data): void
    {
        $this->defer($type, $workspace->id, fn (): array => [$data]);
    }

    /**
     * Note events whose data is built when the transaction flushes, from the
     * state the change left behind.
     *
     * @param  Closure(): array<int, array<string, mixed>>  $build  One `data` per event; none to report nothing after all.
     */
    public function defer(OutboundEventType $type, int $workspaceId, Closure $build): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! $this->locked($workspaceId)) {
            throw new LogicException("An outbound event for workspace {$workspaceId} must be recorded inside OutboundEvents::transaction() for that workspace.");
        }

        $key = $this->nextKey++;

        $this->pending[$key] = [
            'type' => $type,
            'workspace_id' => $workspaceId,
            'actor' => $this->actors->current()->toArray(),
            'occurred_at' => self::timestamp(now()),
            'build' => $build,
        ];

        DB::afterRollBack(function () use ($key): void {
            unset($this->pending[$key]);
        });
    }

    /**
     * The timestamp format every field in an envelope uses: UTC with
     * microseconds, so two events in the same second still order.
     */
    public static function timestamp(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param  array<int, int>  $workspaceIds
     */
    private function lock(array $workspaceIds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = array_values(array_diff(array_unique(array_map('intval', $workspaceIds)), array_keys($this->locked)));
        sort($ids);

        if ($ids === []) {
            return;
        }

        DB::statement('SET LOCAL lock_timeout = '.DB::getPdo()->quote((string) config('snack.agents.lock_timeout')));

        foreach ($ids as $id) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $id]);

            $this->locked[$id] = true;

            // A savepoint that rolls back releases what it locked — including
            // when a later lock in this same loop is the thing that failed.
            DB::afterRollBack(function () use ($id): void {
                unset($this->locked[$id]);
            });
        }
    }

    /**
     * Whether an application transaction is already open on this connection.
     * Asked of the transaction manager rather than the transaction level, so
     * the transaction a test runs inside does not count.
     */
    private function insideAnotherTransaction(): bool
    {
        $connection = DB::connection()->getName();

        return app('db.transactions')->callbackApplicableTransactions()
            ->contains(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection);
    }

    /**
     * Builds every noted event from the state the change left behind, numbers
     * each workspace's in one statement, and writes them all in one more.
     */
    private function flush(): void
    {
        $built = [];

        foreach ($this->pending as $entry) {
            foreach (($entry['build'])() as $data) {
                $built[] = [$entry, $data];
            }
        }

        $this->pending = [];

        if ($built === []) {
            return;
        }

        $workspaceIds = array_values(array_unique(array_map(fn (array $event): int => $event[0]['workspace_id'], $built)));
        $slugs = Workspace::query()->whereKey($workspaceIds)->pluck('slug', 'id');
        $sequences = $this->allocateSequences(array_count_values(array_map(fn (array $event): int => $event[0]['workspace_id'], $built)));
        $now = now();

        $rows = array_map(function (array $event) use ($slugs, &$sequences, $now): array {
            [$entry, $data] = $event;
            $workspaceId = $entry['workspace_id'];

            return [
                'event_id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'sequence' => $sequences[$workspaceId]++,
                'type' => $entry['type']->value,
                'payload' => json_encode([
                    'schema_version' => self::SCHEMA_VERSION,
                    'occurred_at' => $entry['occurred_at'],
                    'instance' => config('app.url'),
                    'workspace' => ['id' => $workspaceId, 'slug' => $slugs->get($workspaceId)],
                    'actor' => $entry['actor'],
                    'data' => $data,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'status' => OutboundEventStatus::Pending->value,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $built);

        $ids = $this->insert($rows);

        /*
         * Queued once the change commits, so not even the job's unique lock is
         * taken inside the transaction it would hold up. A queue that refuses
         * the job must not turn a committed change into an error: the row is
         * already safe, and the sweeper queues it again — `dispatched_at` stays
         * empty until a dispatch has gone through.
         */
        DB::afterCommit(function () use ($ids): void {
            try {
                foreach ($ids as $id) {
                    DeliverOutboundEvent::dispatch($id);
                }

                OutboundEvent::query()->whereKey($ids)->update(['dispatched_at' => now()]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * The first sequence number for each workspace's events, having reserved
     * as many as it has.
     *
     * @param  array<int, int>  $counts  How many events each workspace id has.
     * @return array<int, int>
     */
    private function allocateSequences(array $counts): array
    {
        ksort($counts);
        $first = [];

        foreach ($counts as $workspaceId => $count) {
            $row = DB::selectOne(<<<'SQL'
                INSERT INTO outbound_sequences (workspace_id, value) VALUES (?, ?)
                ON CONFLICT (workspace_id) DO UPDATE SET value = outbound_sequences.value + EXCLUDED.value
                RETURNING value
            SQL, [$workspaceId, $count]);

            $first[$workspaceId] = (int) $row->value - $count + 1;
        }

        return $first;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int> the new rows' ids, in order
     */
    private function insert(array $rows): array
    {
        $query = DB::table('outbound_events');

        $inserted = DB::select(
            $query->getGrammar()->compileInsert($query, $rows).' returning "id"',
            array_merge(...array_map('array_values', $rows)),
        );

        return array_map(fn (stdClass $row): int => (int) $row->id, $inserted);
    }
}
