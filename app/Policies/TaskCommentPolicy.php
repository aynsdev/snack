<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may say something on a card, and who may take it back.
 *
 * Writing a comment is contributing to the project — the same right as moving a
 * card, and for the same reason: a board anybody passing by can annotate is a
 * board nobody trusts. Reading follows the card, which follows the project.
 *
 * Rewriting is the author's alone. Not even a lead may edit somebody else's
 * words: a comment is a record of what a particular person said, and an edit by
 * a third party is that record quietly ceasing to be true while still carrying
 * the original name.
 *
 * Deleting is the author's, plus project leads — the same split as deleting a
 * card, and the same reason. Someone has to be able to clear up a comment posted
 * on the wrong card by somebody now on holiday, and removing a remark leaves the
 * record silent rather than false.
 */
class TaskCommentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, TaskComment $comment): Response
    {
        return $this->projects->view($user, $comment->task->project);
    }

    public function create(User $user, Task $task): Response
    {
        return $this->projects->contribute($user, $task->project);
    }

    public function update(User $user, TaskComment $comment): Response
    {
        $contribute = $this->projects->contribute($user, $comment->task->project);

        if ($contribute->denied()) {
            return $contribute;
        }

        return $comment->author_id === $user->id
            ? Response::allow()
            : Response::deny('Only the person who wrote a comment can edit it.');
    }

    public function delete(User $user, TaskComment $comment): Response
    {
        $contribute = $this->projects->contribute($user, $comment->task->project);

        if ($contribute->denied()) {
            return $contribute;
        }

        if ($comment->author_id === $user->id) {
            return Response::allow();
        }

        return $this->projects->update($user, $comment->task->project)->allowed()
            ? Response::allow()
            : Response::deny('Only the person who wrote this comment, or a project lead, can delete it.');
    }
}
