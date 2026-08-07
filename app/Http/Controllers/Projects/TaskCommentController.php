<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The discussion on a card.
 *
 * JSON rather than Inertia redirects, for the reason {@see TaskController}
 * gives: the panel is open over a live board, and answering a comment with a
 * full page render would close it.
 *
 * Each answer carries the saved comment in the shape the panel draws, so the
 * client stores the server's version — including the `can` flags, which are the
 * policy's answer and not the client's guess.
 */
class TaskCommentController extends Controller
{
    public function store(Workspace $workspace, Task $task, Request $request): JsonResponse
    {
        $this->authorize('create', [TaskComment::class, $task]);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'author_id' => $request->user()->id,
            'body' => trim($validated['body']),
        ]);

        return response()->json([
            'comment' => (new TaskCommentResource($comment->load('author')))->resolve($request),
        ], 201);
    }

    public function update(Workspace $workspace, Task $task, TaskComment $comment, Request $request): JsonResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update([
            'body' => trim($validated['body']),
            // Separate from `updated_at`, which anything at all may move. This
            // is what the "(edited)" mark means, so only a rewrite sets it.
            'edited_at' => now(),
        ]);

        return response()->json([
            'comment' => (new TaskCommentResource($comment->load('author')))->resolve($request),
        ]);
    }

    public function destroy(Workspace $workspace, Task $task, TaskComment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json(['deleted' => true]);
    }
}
