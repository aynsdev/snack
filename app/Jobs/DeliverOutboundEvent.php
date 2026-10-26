<?php

namespace App\Jobs;

use App\Services\Outbound\OutboundDelivery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one outbox row to the agent dispatcher.
 *
 * Queued after commit: dispatched from inside the transaction that wrote the
 * row, it must not reach a worker before that row is visible — and must not be
 * queued at all if the transaction rolls back.
 *
 * Unique per event until it starts, so the sweeper re-dispatching a row whose
 * job is merely waiting in a busy queue does not stack up copies of it. Copies
 * that slip through anyway are harmless; see {@see OutboundDelivery} for why
 * only one of them can send.
 */
class DeliverOutboundEvent implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * One attempt per job. Retries belong to the outbox row — its backoff, its
     * 24-hour window — and a queue-level retry would send on a schedule the row
     * knows nothing about.
     */
    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Long enough to cover a backed-up queue, short enough that a job lost
     * before it ran does not hold its event hostage for long.
     */
    public int $uniqueFor = 300;

    /**
     * On the dispatcher's own queue — see `snack.agents.queue` for why.
     */
    public function __construct(public readonly int $eventId)
    {
        $this->onQueue((string) config('snack.agents.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(OutboundDelivery $delivery): void
    {
        $delivery->attempt($this->eventId);
    }
}
