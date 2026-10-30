<?php

namespace App\Console\Commands;

use App\Enums\OutboundEventStatus;
use App\Jobs\DeliverOutboundEvent;
use App\Models\OutboundEvent;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Re-queues outbox rows that are due and still pending.
 *
 * The delivery job is dispatched when the row commits, and nearly always that
 * is the end of it. This covers the rest: a process that died between the
 * commit and the dispatch, a job lost to a worker restart, a lease that ran out
 * because the worker holding it was killed mid-request. Without it those
 * events would sit in the table for ever, and the receiver would never know a
 * task had been assigned.
 */
#[Signature('snack:sweep-outbound-events')]
#[Description('Re-queue agent dispatcher events that are due for delivery.')]
class SweepOutboundEvents extends Command
{
    /**
     * Enough for any ordinary backlog; a larger one drains over the next few
     * minutes rather than flooding the queue in one go.
     */
    private const BATCH = 500;

    /**
     * How old the oldest pending event may get before somebody is told. The
     * first few retries fit well inside it, so only a dispatcher that has
     * been down for a while — or a queue nobody is working — trips it.
     */
    private const STALE_MINUTES = 10;

    /**
     * How long a queued delivery job is given before it is assumed lost.
     */
    private const REDISPATCH_AFTER_MINUTES = 15;

    public function handle(OutboundEvents $events): int
    {
        if (! $events->enabled()) {
            $this->info('Outbound events are not configured.');

            return self::SUCCESS;
        }

        /*
         * Only rows whose job looks lost: never queued, or queued long enough
         * ago that a worker should have run it by now. Re-queueing every due
         * row each minute would pile copies of a job behind one that is merely
         * waiting its turn in a busy queue.
         */
        $ids = OutboundEvent::query()
            ->due()
            ->where(fn (Builder $query) => $query
                ->whereNull('dispatched_at')
                ->orWhere('dispatched_at', '<=', now()->subMinutes(self::REDISPATCH_AFTER_MINUTES)))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverOutboundEvent::dispatch((int) $id);
        }

        OutboundEvent::query()->whereKey($ids)->update(['dispatched_at' => now()]);

        $this->info("Outbound events re-queued: {$ids->count()}.");

        $this->warnIfStale();

        return self::SUCCESS;
    }

    /**
     * Re-queueing is all this command can do; when rows still sit pending
     * after that, the jobs are not being run — most likely because no worker
     * listens on the outbound queue — or the dispatcher keeps refusing them.
     */
    private function warnIfStale(): void
    {
        $oldest = OutboundEvent::query()->where('status', OutboundEventStatus::Pending)->min('created_at');

        if ($oldest === null || Carbon::parse($oldest)->greaterThan(now()->subMinutes(self::STALE_MINUTES))) {
            return;
        }

        Log::warning('Agent dispatcher events have been pending for more than '.self::STALE_MINUTES.' minutes. Check that a worker is running on the outbound queue and that the dispatcher is reachable.', [
            'oldest_pending_at' => Carbon::parse($oldest)->toIso8601String(),
            'queue' => config('snack.agents.queue'),
        ]);
    }
}
