<?php

namespace App\Jobs;

use App\Services\MessageIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Refreshes one message's search vector.
 *
 * Dispatched when a message is sent or edited, and again when an attachment
 * finishes processing — the vector folds in filenames, so the two can finish in
 * either order.
 *
 * Off the request path because building the vector reads the sender, the
 * channel, and the attachment list, and the send endpoint is what a person is
 * waiting on when they press Enter. A message is therefore searchable a moment
 * after it is sent rather than the instant it is, which is the right trade: it
 * is already on everyone's screen by then.
 *
 * Takes an id rather than the model for the same reason {@see ProcessAttachment}
 * does — a message deleted between dispatch and execution is a no-op, not a
 * failure.
 */
class IndexMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $messageId) {}

    /**
     * Serialized per message so an edit and an attachment finishing at the same
     * moment cannot interleave into a vector that reflects neither.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->messageId))->releaseAfter(5)];
    }

    public function handle(MessageIndexer $indexer): void
    {
        $indexer->index($this->messageId);
    }
}
