<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Task authorization, which is almost entirely project authorization.
 *
 * A card has no visibility of its own: it is on a board, and who may read and
 * work that board is settled by {@see ProjectPolicy}. Everything here delegates,
 * so there is exactly one definition of "is this person on this project" and no
 * way for a task route to be more permissive than the board it belongs to.
 *
 * The one right that is genuinely the task's own is {@see delete()} — see below.
 */
class TaskPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, Task $task): Response
    {
        return $this->projects->view($user, $task->project);
    }

    public function create(User $user, Project $project): Response
    {
        return $this->projects->contribute($user, $project);
    }

    /**
     * Editing a task, moving it between columns, and assigning it.
     *
     * Any contributor, not just the reporter or the assignee: a board where only
     * the author may drag their own card is a board that goes stale the first
     * time somebody is on holiday.
     */
    public function update(User $user, Task $task): Response
    {
        return $this->projects->contribute($user, $task->project);
    }

    /**
     * Deleting a task outright.
     *
     * Narrower than editing, because it is the one action on a card that leaves
     * no trace: a reference to PWA-1499 in a channel from last month becomes a
     * dead link and nobody can say what it used to be. The person who filed it
     * may take it back, and leads may clear up; everyone else moves it to a Done
     * column instead, which is what "we are not doing this" normally means.
     */
    public function delete(User $user, Task $task): Response
    {
        $contribute = $this->projects->contribute($user, $task->project);

        if ($contribute->denied()) {
            return $contribute;
        }

        if ($task->reporter_id === $user->id) {
            return Response::allow();
        }

        return $this->projects->update($user, $task->project)->allowed()
            ? Response::allow()
            : Response::deny('Only the person who filed this task, or a project lead, can delete it.');
    }
}
