<?php

namespace App\Support;

use App\Models\Task;
use App\Models\TaskRelation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A card's relations to other cards, as one person sees them.
 *
 * Each relation is stored once, from the side it was said from, so this reads
 * both directions and words each from `$task`'s side — "blocks PWA-2" on one
 * card, "is blocked by PWA-1" on the other. A related card the viewer cannot
 * open is left out, the same 404-not-403 rule as everywhere else: a relation
 * must not reveal a card in a project the viewer is not on.
 */
final class TaskRelations
{
    /**
     * @return array<int, array{id: int, type: string, wording: string, task: Task}>
     */
    public static function for(Task $task, User $viewer): array
    {
        $outgoing = $task->outgoingRelations()->with('relatedTask.project', 'relatedTask.column')->get()
            ->map(fn (TaskRelation $relation): array => [
                'id' => $relation->id,
                'type' => $relation->type->value,
                'wording' => $relation->type->outward(),
                'task' => $relation->relatedTask,
            ]);

        $incoming = $task->incomingRelations()->with('task.project', 'task.column')->get()
            ->map(fn (TaskRelation $relation): array => [
                'id' => $relation->id,
                'type' => $relation->type->value,
                'wording' => $relation->type->inward(),
                'task' => $relation->task,
            ]);

        return $outgoing->concat($incoming)
            ->filter(fn (array $relation): bool => Gate::forUser($viewer)->allows('view', $relation['task']))
            ->values()
            ->all();
    }

    /**
     * The same list as the task panel draws it.
     *
     * @return array<int, array{id: int, type: string, wording: string, task: array{id: int, reference: string, title: string, status: string, is_complete: bool}}>
     */
    public static function payload(Task $task, User $viewer): array
    {
        return array_map(fn (array $relation): array => [
            'id' => $relation['id'],
            'type' => $relation['type'],
            'wording' => $relation['wording'],
            'task' => [
                'id' => $relation['task']->id,
                'reference' => $relation['task']->reference(),
                'title' => $relation['task']->title,
                'status' => $relation['task']->column->name,
                'is_complete' => $relation['task']->isComplete(),
            ],
        ], self::for($task, $viewer));
    }
}
