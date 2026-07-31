<?php

namespace App\Jobs;

use App\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Raises the notifications one message is owed.
 *
 * Off the request path for the same reason {@see IndexMessage} is: the fan-out
 * reads every membership in the container and then queues a delivery per
 * recipient, and the person who pressed Enter is waiting on the response.
 *
 * Takes an id rather than the model so a message deleted between dispatch and
 * execution is a no-op instead of a failure — and so the job cannot deliver a
 * stale copy of a body that has since been edited.
 */
class DeliverMessageNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $messageId) {}

    /**
     * Serialized per message so an edit re-running the fan-out cannot interleave
     * with the original and notify somebody twice for one message.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->messageId))->releaseAfter(5)];
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->messageId);
    }
}
