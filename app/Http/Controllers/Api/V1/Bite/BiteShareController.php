<?php

namespace App\Http\Controllers\Api\V1\Bite;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\BiteChatSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Bite chat someone shared with the workspace, read-only.
 *
 * Sharing and unsharing already answer with JSON and are routed to the web
 * controller; only reading a share needs its own shape here, because the web
 * one answers JSON requests with the history alone.
 */
class BiteShareController extends Controller
{
    /**
     * The transcript up to the moment it was shared. Older pages with `before`.
     */
    public function show(Workspace $workspace, string $token, Request $request, BiteChatSharing $sharing): JsonResponse
    {
        abort_unless($request->user()->belongsToWorkspace($workspace), 404);

        $chat = $workspace->conversations()->biteChats()->where('share_token', $token)->firstOrFail();

        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json([
            'data' => [
                'shared_chat' => [
                    'title' => $chat->name ?? 'Shared chat',
                    'owner_id' => $chat->created_by,
                    'shared_at' => $chat->shared_at?->toIso8601String(),
                ],
                'history' => $sharing->page($chat, $request->integer('before') ?: null),
                'share_token' => $token,
            ],
        ]);
    }
}
