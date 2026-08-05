<?php

namespace App\Actions\Projects;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tells somebody a task is now theirs.
 *
 * # Only on the change, and never to yourself
 *
 * Called with the assignee the task had *before* the save, so re-saving a card
 * for an unrelated edit does not re-announce an assignment that has not moved.
 * Picking work up yourself is silent for the obvious reason: you were there.
 *
 * The payload is assembled here, with the task and its project already in
 * memory, and handed over as plain data — so the queued notification never
 * lazy-loads a relation and a task deleted between the click and the worker
 * running cannot turn this into a failed job. The same arrangement
 * {@see App\Services\NotificationDispatcher} makes for messages.
 */
class AnnounceAssignment
{
    public function handle(Task $task, User $actor, ?int $previousAssigneeId): void
    {
        $assigneeId = $task->assignee_id;

        if ($assigneeId === null || $assigneeId === $previousAssigneeId || $assigneeId === $actor->id) {
            return;
        }

        $assignee = $task->assignee ?? User::find($assigneeId);

        if ($assignee === null) {
            return;
        }

        $project = $task->project;

        Notification::send($assignee, new TaskAssignedNotification(
            workspace: $project->workspace,
            payload: [
                'actor' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'username' => $actor->username,
                    'avatar_url' => $actor->avatarUrl(),
                ],
                'task' => [
                    'id' => $task->id,
                    'reference' => $task->reference(),
                    'title' => $task->title,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'project_slug' => $project->slug,
                ],

                // The row's own body text. A notification is a snapshot of the
                // moment it was raised — see MessageNotification — so the title
                // is copied rather than read back off a task that may since have
                // been renamed out from under it.
                'preview' => $task->reference().' — '.$task->title,
            ],
        ));
    }
}
