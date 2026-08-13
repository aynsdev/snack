<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToContainer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bite has started, or finished, working on an answer.
 *
 * An answer is a chain of provider round trips — searches, a file read, then the
 * reply — and can take the better part of a minute. Without this the person who
 * asked sees nothing at all until the message lands, which is indistinguishable
 * from a summon that did not register, an assistant that is switched off, or a
 * queue worker that is not running. All three happen, and all three used to look
 * identical.
 *
 * # Why this is not the typing indicator
 *
 * Typing rides on client whispers — see {@see resources/js/hooks/use-typing.ts},
 * which explains why it never touches the server. Bite has no browser and no
 * socket: it is a queue worker. There is nothing to whisper from, so the only
 * way for it to say anything before its message exists is a server broadcast.
 *
 * # Why it broadcasts *now*
 *
 * This is the whole point of the class, and it is easy to undo by accident.
 *
 * Every other broadcast in the application is queued, which is correct: they are
 * dispatched from a request that should not wait on a socket write. This one is
 * dispatched from inside {@see App\Jobs\AnswerWithBite}, which is itself the job
 * currently occupying the worker. A queued broadcast would be pushed onto the
 * back of the same queue and picked up only once that job has finished — which
 * is to say, "Bite is replying" would arrive *after* Bite's reply did. The
 * indicator would flash on at the moment it stopped being true.
 *
 * So it goes out synchronously, on the answering worker's own time. It costs one
 * HTTP call to Reverb at each end of an operation already measured in seconds.
 *
 * # Why the payload carries an expiry
 *
 * For the same reason typing signals do: the "stopped" half cannot be relied on.
 * A worker killed mid-answer, a provider call that hangs past the job's timeout,
 * or a deploy in the middle of a reply all end the work without ending the
 * signal. The client therefore expires what it is shown, so the worst failure is
 * an indicator that lingers rather than one that never leaves.
 */
class BiteReplying implements ShouldBroadcastNow
{
    use BroadcastsToContainer, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        /**
         * The message that summoned Bite — not the reply, which does not exist
         * yet. It is what decides where this is heard: a summon inside a thread
         * is answered inside that thread, so the indicator belongs there too.
         */
        public readonly Message $summons,
        public readonly User $bot,
        public readonly bool $replying,
    ) {}

    protected function message(): Message
    {
        return $this->summons;
    }

    public function broadcastAs(): string
    {
        return 'bite.replying';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->bot->id,
            'name' => $this->bot->name,
            'replying' => $this->replying,
            /*
             * Seconds rather than a timestamp: the client is counting down from
             * the moment the frame arrives, and a wall-clock deadline would be
             * read against a browser clock that may disagree with the server's.
             */
            'expires_in' => $this->replying
                ? (int) config('snack.bite.replying_ttl_seconds', 60)
                : 0,
        ];
    }
}
