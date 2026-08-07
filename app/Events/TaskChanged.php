<?php

namespace App\Events;

use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A card was filed, edited, moved, or deleted.
 *
 * # Why the whole card, every time
 *
 * The payload is the task as the board draws it, not a diff. A board is a shared
 * surface several people drag on at once, and a client applying partial updates
 * has to be right about the order they arrive in — which over a socket it cannot
 * be. Sending the settled state means the last message to arrive wins, which is
 * also what the database says, so a tab left open all afternoon cannot drift
 * into a version of the board that never existed.
 *
 * The same choice {@see ReactionChanged} makes, for the same reason.
 *
 * # Deletes
 *
 * A deleted task has no state left to send, so {@see deleted()} builds the event
 * from the id and reference captured before the row went away.
 */
class TaskChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'created'|'updated'|'moved'|'deleted'  $action
     */
    private function __construct(
        public readonly Project $project,
        public readonly string $action,
        public readonly int $taskId,
        public readonly string $reference,
        public readonly ?Task $task = null,
    ) {}

    /**
     * A task that still exists, with everything a card is drawn from loaded.
     *
     * @param  'created'|'updated'|'moved'  $action
     */
    public static function for(Task $task, string $action): self
    {
        $task->loadMissing(['project', 'assignee', 'labels', 'epic', 'release']);

        return new self(
            project: $task->project,
            action: $action,
            taskId: $task->id,
            reference: $task->reference(),
            task: $task,
        );
    }

    public static function deleted(Project $project, int $taskId, string $reference): self
    {
        return new self(
            project: $project,
            action: 'deleted',
            taskId: $taskId,
            reference: $reference,
        );
    }

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('project.'.$this->project->id)];
    }

    public function broadcastAs(): string
    {
        return 'task.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'task_id' => $this->taskId,
            'reference' => $this->reference,
            'task' => $this->task === null
                ? null
                : (new TaskResource($this->task))->resolve(),
        ];
    }
}
