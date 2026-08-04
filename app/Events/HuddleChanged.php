<?php

namespace App\Events;

use App\Http\Resources\HuddleResource;
use App\Models\Huddle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A huddle started, someone joined or left it, or it ended.
 *
 * Goes to the container's presence channel, so everyone with that channel or DM
 * open sees the huddle bar appear, its avatar stack change, and the whole thing
 * disappear when the last person hangs up.
 *
 * # One event for all four transitions
 *
 * The alternative — HuddleStarted, HuddleJoined, HuddleLeft, HuddleEnded — asks
 * every client to reconstruct the roster by applying deltas in order, and
 * WebSocket delivery guarantees neither order nor arrival. One dropped "left"
 * frame and a channel shows somebody in a huddle they walked out of half an hour
 * ago, with nothing to correct it until a page reload. Carrying the full state
 * every time means each frame is independently sufficient and the newest one
 * always wins, which is the same trade reactions and pins make.
 *
 * The payload is small — a huddle is capped at
 * `snack.huddles.max_participants` — so there is nothing to be saved by sending
 * a delta anyway.
 *
 * # Why the roster is not a leak
 *
 * The subscribers are exactly the people the container policy already admits,
 * and what they learn is who among their own channel members is on a call in
 * that channel. That is the fact the huddle bar exists to display.
 */
class HuddleChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Huddle $huddle) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $name = $this->huddle->isInChannel()
            ? 'channel.'.$this->huddle->channel_id
            // Named by the conversation's opaque public id, matching the
            // binding in routes/channels.php.
            : 'conversation.'.$this->huddle->conversation?->public_id;

        // Presence channels, because that is what containers are — the same
        // subscription the message timeline and typing indicators ride on.
        return [new PresenceChannel($name)];
    }

    public function broadcastAs(): string
    {
        return 'huddle.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         * The roster is re-read here rather than trusted from the instance the
         * dispatcher held. This runs on a queue worker, potentially seconds
         * later and after further joins and leaves, and an in-memory relation
         * loaded before the write would broadcast a roster that was already
         * stale when it was serialized — which is precisely the drift the
         * full-state design above exists to prevent.
         */
        $huddle = $this->huddle->fresh(['channel', 'conversation', 'activeParticipants.user']);

        if ($huddle === null) {
            // The container was deleted out from under the huddle. Nothing
            // meaningful left to announce, and the clients will drop their
            // copy when the container itself goes.
            return ['huddle' => null];
        }

        return ['huddle' => (new HuddleResource($huddle))->resolve()];
    }
}
