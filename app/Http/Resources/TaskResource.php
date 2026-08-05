<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\TaskLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card, in the shape the board draws it.
 *
 * The same shape whether it arrives as a page prop or over the socket — see
 * {@see App\Events\TaskChanged} — so a client applying a live update never has
 * to convert between two versions of a task.
 *
 * `assignee`, `labels`, and `epic` are expected to be eager-loaded; a board is
 * a card per row, so resolving any of them here would be an N+1 the width of the
 * whole project. {@see Task::scopeWithCardData()} is what callers use.
 *
 * Mirrors the `Task` type in resources/js/types/snack.ts.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference(),
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type->value,
            'priority' => $this->priority->value,

            'project_id' => $this->project_id,
            'board_column_id' => $this->board_column_id,
            'sprint_id' => $this->sprint_id,
            'position' => $this->position,

            'story_points' => $this->story_points,
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_complete' => $this->isComplete(),

            'assignee' => $this->whenLoaded(
                'assignee',
                fn (): ?array => $this->assignee === null
                    ? null
                    : (new UserResource($this->assignee))->resolve($request),
            ),

            'labels' => $this->whenLoaded(
                'labels',
                fn (): array => $this->labels
                    ->map(fn (TaskLabel $label): array => [
                        'id' => $label->id,
                        'name' => $label->name,
                        'color' => $label->color,
                    ])
                    ->all(),
            ),

            /*
             * The epic this card belongs to, flattened to the three fields the
             * card renders. Not a nested TaskResource: an epic is itself a task,
             * and nesting would recurse a level deeper than anything draws.
             */
            'epic' => $this->whenLoaded(
                'epic',
                fn (): ?array => $this->epic === null
                    ? null
                    : [
                        'id' => $this->epic->id,
                        'title' => $this->epic->title,
                        'reference' => $this->epic->reference(),
                    ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
