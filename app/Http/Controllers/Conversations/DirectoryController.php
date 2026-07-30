<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The people you can start a conversation with.
 *
 * Answers with JSON because the new-message picker filters as you type, and an
 * Inertia visit per keystroke would replace the page's props each time.
 *
 * Gated on `create` for a conversation, so a guest — who may not start one —
 * cannot use this as a back door to the workspace's member directory. That is
 * the same reason the roster is capped rather than returned whole: this is a
 * picker, not an export.
 */
class DirectoryController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('create', [Conversation::class, $workspace]);

        $term = trim((string) $request->string('q'));

        $people = $workspace->activeUsers()
            // Messaging yourself is not a conversation Snack models, so you are
            // not in your own picker.
            ->whereKeyNot($request->user()->id)
            ->when($term !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('users.name', 'ilike', '%'.$term.'%')
                    ->orWhere('users.username', 'ilike', '%'.$term.'%'),
            ))
            ->orderBy('users.name')
            ->limit(50)
            ->get();

        return response()->json([
            'people' => $people
                ->map(fn (User $user): array => (new UserResource($user))->resolve($request))
                ->all(),
        ]);
    }
}
