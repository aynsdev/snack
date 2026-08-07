<?php

namespace App\Http\Resources;

use App\Models\Release;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One release: what it is called, whether it has gone out, when, how far along
 * it is, and — on its own page — what is in it.
 *
 * The counts are what the table's progress bar is drawn from, and they arrive as
 * counts rather than as contents: a list of releases draws a bar per row, and
 * sending every card of every version to compute two numbers would be several
 * board-sized payloads for one screen. The contents come only with the release's
 * own page, where they are actually listed.
 *
 * Mirrors the `Release` type in resources/js/types/snack.ts.
 *
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'notes' => $this->notes,

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'released_at' => $this->released_at?->toIso8601String(),

            'state' => $this->state()->value,

            // Late is "the day passed and it has not gone out" — see
            // Release::isOverdue() for why the contents do not decide it.
            'is_overdue' => $this->isOverdue(),

            'task_count' => $this->whenCounted('tasks', default: 0),
            'completed_count' => $this->when(
                $this->completed_tasks_count !== null,
                fn (): int => (int) $this->completed_tasks_count,
                0,
            ),

            'tasks' => $this->whenLoaded(
                'tasks',
                fn (): array => $this->tasks
                    ->map(fn (Task $task): array => [
                        'id' => $task->id,
                        'reference' => $task->reference(),
                        'title' => $task->title,
                        'type' => $task->type->value,
                        'is_complete' => $task->isComplete(),
                    ])
                    ->all(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
