<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Huddles\JoinHuddle;
use App\Actions\Huddles\LeaveHuddle;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\Workspace;
use App\Services\LiveKit\HuddleConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining and leaving a conversation's huddle.
 *
 * The mirror of {@see App\Http\Controllers\Channels\HuddleController}, and
 * deliberately a separate controller for the reason the message endpoints are:
 * the route has to name a container, and the two containers bind differently —
 * a conversation by its opaque public id, a channel by its slug scoped to the
 * workspace. Everything past that point is the same shared action.
 *
 * A huddle in a DM is what most people actually mean by "call me": no room to
 * create, nobody to invite, just the person already on the other end.
 */
class HuddleController extends Controller
{
    public function __construct(private readonly HuddleConnection $connection) {}

    /**
     * Start the conversation's huddle, or walk into the one already running.
     */
    public function store(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        JoinHuddle $joinHuddle,
    ): JsonResponse {
        $live = Huddle::query()->live()->forContainer($conversation)->first();

        // See the channel controller for why the applicable check depends on
        // whether a huddle already exists.
        if ($live !== null) {
            $this->authorize('join', $live);
        } else {
            $this->authorize('create', [Huddle::class, $conversation]);
        }

        $huddle = $joinHuddle->handle($conversation, $request->user());

        return response()->json(
            $this->connection->for($huddle, $request->user(), $request),
        );
    }

    /**
     * Hang up. Idempotent — see the channel controller.
     */
    public function destroy(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        LeaveHuddle $leaveHuddle,
    ): JsonResponse {
        $huddle = Huddle::query()->live()->forContainer($conversation)->first();

        if ($huddle === null) {
            return response()->json(['huddle' => null]);
        }

        $this->authorize('leave', $huddle);

        $leaveHuddle->handle($huddle, $request->user());

        return response()->json(['huddle' => null]);
    }
}
