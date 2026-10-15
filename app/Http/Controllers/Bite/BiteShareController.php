<?php

namespace App\Http\Controllers\Bite;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\BiteChatSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BiteShareController extends Controller
{
    public function store(Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $lastMessageId = $conversation->messages()->rootLevel()->max('id');
        abort_if($lastMessageId === null, 422, 'Send a message before sharing this chat.');

        $conversation->forceFill([
            'share_token' => $conversation->share_token ?? Str::random(40),
            'shared_through_message_id' => $lastMessageId,
            'shared_at' => now(),
        ])->save();

        return response()->json(['url' => route('bite.shared.show', [
            'workspace' => $workspace->slug,
            'token' => $conversation->share_token,
        ])]);
    }

    public function destroy(Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->forceFill(['share_token' => null, 'shared_through_message_id' => null, 'shared_at' => null])->save();

        return response()->json(['revoked' => true]);
    }

    public function show(Workspace $workspace, string $token, Request $request, BiteChatSharing $sharing): Response|JsonResponse
    {
        abort_unless($request->user()->belongsToWorkspace($workspace), 404);
        $chat = $workspace->conversations()->biteChats()->where('share_token', $token)->firstOrFail();
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $history = $sharing->page($chat, $request->integer('before') ?: null);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($history);
        }

        return Inertia::render('bite/shared', [
            'sharedChat' => [
                'title' => $chat->name ?? 'Shared chat',
                'owner_id' => $chat->created_by,
                'shared_at' => $chat->shared_at?->toIso8601String(),
            ],
            'history' => $history,
            'shareToken' => $token,
        ]);
    }
}
