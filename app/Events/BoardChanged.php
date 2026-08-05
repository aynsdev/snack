<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The board itself changed shape: a column was added, renamed or removed, or a
 * sprint started or finished.
 *
 * # Why this carries nothing
 *
 * Unlike {@see TaskChanged}, which sends the card it is about, this is a nudge:
 * the open tabs reload the board. Starting a sprint moves every committed task
 * onto it at once and completing one takes most of them off again, so the
 * "changed" payload would be the whole board — at which point sending it twice,
 * once here and once as the page's own props, is two definitions of what a board
 * is that can disagree.
 *
 * These are rare events. A reload that costs one request is the right trade for
 * a board that is never subtly wrong.
 */
class BoardChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Project $project) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('project.'.$this->project->id)];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['project_id' => $this->project->id];
    }
}
