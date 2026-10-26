<?php

namespace App\Services\Outbound;

use App\Enums\OutboundEventType;
use App\Models\Project;
use App\Models\Task;

/**
 * Tells the agent dispatcher when a task's title or description changes.
 *
 * Those are what an agent's plan was built from, so a plan approved against
 * yesterday's description must not be executed against today's. The receiver
 * throws the plan away and has it made again.
 *
 * Called from inside the transaction that saved the task — see
 * {@see TaskEligibilityEvents::transaction()}, which hands over the locked
 * project. Noted after that edit's eligibility event, so the receiver hears
 * who the task belongs to before what it now says.
 */
class TaskContentEvents
{
    public const FIELDS = ['title', 'description'];

    public function __construct(
        private readonly OutboundEvents $events,
        private readonly OutboundPayloads $payloads,
    ) {}

    /**
     * @param  Project|null  $locked  As handed out by {@see TaskEligibilityEvents::transaction()}; null when nothing is reported.
     */
    public function saved(?Project $locked, Task $task): void
    {
        $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $task->wasChanged($field)));

        if ($changed === [] || $locked === null) {
            return;
        }

        $this->events->defer(OutboundEventType::TaskContentChanged, $locked->workspace_id, function () use ($locked, $task, $changed): array {
            $fresh = Task::query()->with(['column:id,name', 'labels'])->find($task->id);

            if ($fresh === null) {
                return [];
            }

            return [[
                'project' => $this->payloads->project($locked, $locked->agentChannel),
                'task_id' => $fresh->id,
                'reference' => $locked->reference($fresh->number),
                'task' => $this->payloads->task($locked, $fresh),
                'changed' => $changed,
            ]];
        });
    }
}
