<?php

namespace App\Http\Controllers\Api\V1\Huddles;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Huddles that have happened, newest first — the mirror of
 * {@see \App\Http\Controllers\Huddles\HuddleHistoryController}. Only huddles in
 * channels the viewer has joined and conversations they are in: a participant
 * list says who was talking to whom.
 */
class HuddleHistoryController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $user = $request->user();

        $huddles = Huddle::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query
                ->whereHas('channel', fn ($channel) => $channel->whereMember($user))
                ->orWhereIn('conversation_id', Conversation::query()->visibleTo($user)->select('id')),
            )
            ->with([
                'channel:id,name,slug,type,workspace_id',
                'conversation',
                'participants.user',
                'starter',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit((int) config('snack.huddles.history_limit', 50))
            ->get();

        return response()->json([
            'data' => $huddles
                ->map(fn (Huddle $huddle): array => [
                    'id' => $huddle->public_id,
                    'container' => ContainerSummary::forContainer($huddle->container()),
                    'started_at' => $huddle->started_at->toIso8601String(),
                    'ended_at' => $huddle->ended_at?->toIso8601String(),
                    'is_live' => $huddle->isLive(),
                    // Null while live: it is still accruing one.
                    'duration_seconds' => $huddle->ended_at === null
                        ? null
                        : $huddle->started_at->diffInSeconds($huddle->ended_at),
                    'started_by' => $huddle->starter === null
                        ? null
                        : (new UserResource($huddle->starter))->resolve($request),
                    // Everyone who was on the call, not who is still there.
                    'participants' => $huddle->participants
                        ->pluck('user')
                        ->filter()
                        ->unique('id')
                        ->map(fn (User $participant): array => (new UserResource($participant))->resolve($request))
                        ->values()
                        ->all(),
                ])
                ->all(),
        ]);
    }
}
