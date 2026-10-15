<?php

namespace App\Http\Resources;

use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\TaskLink;
use App\Support\BranchName;
use App\Support\TaskRelations;
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

            /*
             * Composed here rather than in the panel that copies it, so every
             * surface offering a branch offers the same one. See BranchName.
             */
            'branch_name' => BranchName::for($this->resource),

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

            /*
             * The version this ships in, flattened to what a picker and a card
             * render. A second placement beside `sprint_id` and never a
             * substitute for it — when work is done and when it goes out are
             * different questions. See the releases migration.
             */
            'release' => $this->whenLoaded(
                'release',
                fn (): ?array => $this->release === null
                    ? null
                    : [
                        'id' => $this->release->id,
                        'name' => $this->release->name,
                    ],
            ),

            /*
             * Both are `whenLoaded` and neither is ever loaded by the board:
             * a card carries its discussion and its files only when somebody
             * opened it. A list of eighty cards must not pay for either.
             */
            'comments' => $this->whenLoaded(
                'comments',
                fn (): array => TaskCommentResource::collection($this->comments)->resolve($request),
            ),

            'attachments' => $this->whenLoaded(
                'attachments',
                fn (): array => $this->attachments
                    ->map(fn (MessageAttachment $attachment): array => AttachmentResource::payload($attachment))
                    ->all(),
            ),

            /*
             * How far along the card's subtasks are, for the badge on the card.
             * Counted with the board's query; a single card (an edit, a
             * broadcast) counts its own so the badge does not vanish when one
             * card is redrawn.
             */
            'subtask_progress' => $this->subtaskProgress(),

            /*
             * The subtasks themselves, only with an opened card — the panel's
             * checklist, flattened to what a row draws.
             */
            'subtasks' => $this->whenLoaded(
                'children',
                fn (): array => $this->children
                    ->sortBy('number')
                    ->map(fn (Task $child): array => [
                        'id' => $child->id,
                        'reference' => $this->project->reference($child->number),
                        'title' => $child->title,
                        'type' => $child->type->value,
                        'status' => $child->column->name,
                        'is_complete' => $child->isComplete(),
                        'assignee' => $child->assignee === null
                            ? null
                            : (new UserResource($child->assignee))->resolve($request),
                    ])
                    ->values()
                    ->all(),
            ),

            /*
             * Links, like the discussion, only come with an opened card. The
             * relations are read from both sides and filtered to what the
             * viewer may open — see TaskRelations — so they ride on the same
             * `links` load rather than a relation of their own.
             */
            'links' => $this->whenLoaded(
                'links',
                fn (): array => $this->links->map(fn (TaskLink $link): array => self::link($link))->all(),
            ),

            'relations' => $this->whenLoaded(
                'links',
                fn (): array => TaskRelations::payload($this->resource, $request->user()),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One web link as the task panel draws it.
     *
     * @return array{id: int, url: string, title: string|null, label: string}
     */
    public static function link(TaskLink $link): array
    {
        return [
            'id' => $link->id,
            'url' => $link->url,
            'title' => $link->title,
            'label' => $link->label(),
        ];
    }

    /**
     * Subtasks done and in all, or null for a card with none.
     *
     * @return array{done: int, total: int}|null
     */
    private function subtaskProgress(): ?array
    {
        $total = $this->resource->getAttribute('subtasks_count')
            ?? $this->resource->children()->count();

        if ($total === 0) {
            return null;
        }

        $done = $this->resource->getAttribute('completed_subtasks_count')
            ?? $this->resource->children()->whereNotNull('completed_at')->count();

        return ['done' => (int) $done, 'total' => (int) $total];
    }
}
