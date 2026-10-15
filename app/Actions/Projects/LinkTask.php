<?php

namespace App\Actions\Projects;

use App\Enums\TaskRelationType;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\TaskRelation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Linking a card to the web and to other cards.
 *
 * One action for the task panel and the MCP tools, so both refuse the same
 * things: a link that is not http(s), the same URL twice, a card related to
 * itself, a card in another workspace, and a relation that already exists in
 * either direction. Authorization stays with the caller — this assumes the
 * actor may change `$task` and see `$other`.
 */
class LinkTask
{
    /**
     * Attach a web link, or retitle the one already there for the same URL.
     */
    public function addLink(Task $task, User $actor, string $url, ?string $title = null): TaskLink
    {
        $url = trim($url);
        $title = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 200) : null;

        if (! preg_match('#^https?://\S+$#i', $url) || mb_strlen($url) > 2048) {
            throw ValidationException::withMessages(['url' => 'A link has to be a web address starting with http:// or https://.']);
        }

        $link = $task->links()->firstOrNew(['url' => $url]);
        $link->created_by ??= $actor->id;

        if ($title !== null || ! $link->exists) {
            $link->title = $title ?? $link->title;
        }

        $link->save();

        return $link;
    }

    public function removeLink(TaskLink $link): void
    {
        $link->delete();
    }

    /**
     * Say how `$task` relates to `$other`, from `$task`'s side.
     */
    public function relate(Task $task, Task $other, TaskRelationType $type, User $actor): TaskRelation
    {
        if ($task->is($other)) {
            throw ValidationException::withMessages(['related_task_id' => 'A task cannot be linked to itself.']);
        }

        if ($task->project->workspace_id !== $other->project->workspace_id) {
            throw ValidationException::withMessages(['related_task_id' => 'Tasks can only be linked within one workspace.']);
        }

        if ($this->between($task, $other)->where('type', $type)->exists()) {
            throw ValidationException::withMessages(['related_task_id' => "{$task->reference()} and {$other->reference()} are already linked that way."]);
        }

        return TaskRelation::create([
            'task_id' => $task->id,
            'related_task_id' => $other->id,
            'type' => $type,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Remove every relation between the two cards, whichever side said it.
     *
     * @return int how many were removed
     */
    public function unrelate(Task $task, Task $other): int
    {
        return $this->between($task, $other)->delete();
    }

    /**
     * @return Builder<TaskRelation>
     */
    private function between(Task $task, Task $other): Builder
    {
        return TaskRelation::query()->where(fn ($query) => $query
            ->where(fn ($pair) => $pair->where('task_id', $task->id)->where('related_task_id', $other->id))
            ->orWhere(fn ($pair) => $pair->where('task_id', $other->id)->where('related_task_id', $task->id)));
    }
}
