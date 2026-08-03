<?php

namespace App\Events;

use App\Enums\PresenceStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody's availability changed.
 *
 * Broadcast to every workspace they belong to, because that is exactly the set
 * of people entitled to see them at all — a green dot is a small thing to leak,
 * but it still says "this person is at their desk right now", and it should not
 * travel further than the rooms they share.
 *
 * # Why only on a change
 *
 * A heartbeat arrives from every open tab every minute. Broadcasting each one
 * would put a workspace-wide fan-out on a timer, forever, to announce that
 * nothing happened. {@see App\Actions\Presence\RecordPresence} dispatches this
 * only when the stored status actually moves, so an idle workspace is silent.
 *
 * # Why the workspace ids are passed in
 *
 * They are resolved by the caller, which already has the user loaded, rather
 * than looked up here — the event serializes onto the queue, and re-querying
 * memberships on the worker would turn one broadcast into a query per delivery.
 */
class PresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $workspaceIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly PresenceStatus $status,
        public readonly array $workspaceIds,
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $workspaceId): PresenceChannel => new PresenceChannel('workspace.'.$workspaceId),
            $this->workspaceIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'presence_status' => $this->status->value,
        ];
    }
}
