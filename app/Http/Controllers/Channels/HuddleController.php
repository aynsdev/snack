<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Huddles\JoinHuddle;
use App\Actions\Huddles\LeaveHuddle;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\Workspace;
use App\Services\LiveKit\HuddleConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining and leaving a channel's huddle.
 *
 * One endpoint for "start" and "join", because they are one button: pressing
 * Huddle means "put me in the call here", and whether there is already a call is
 * something only the server can answer without racing. See {@see JoinHuddle}.
 *
 * These answer with JSON rather than Inertia responses, for the reason the
 * message endpoints do: a huddle is live client-owned state that is also being
 * updated over the WebSocket, and an Inertia prop replaced wholesale on each
 * visit would fight the broadcast. The response additionally carries a
 * credential, which has no business being a page prop at all.
 *
 * Every action declares {workspace} first, matching the route's parameter order.
 */
class HuddleController extends Controller
{
    public function __construct(private readonly HuddleConnection $connection) {}

    /**
     * Start the channel's huddle, or walk into the one already running.
     */
    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        JoinHuddle $joinHuddle,
    ): JsonResponse {
        $live = Huddle::query()->live()->forContainer($channel)->first();

        /*
         * Two different questions, and which one applies depends on whether
         * there is a huddle yet. `create` asks whether this person may hold a
         * call in this channel at all; `join` additionally refuses a huddle that
         * is full or has just ended. Asking only the first would let somebody
         * past the capacity limit; asking only the second has nothing to ask
         * about when the channel has no huddle.
         *
         * The gap between this check and the join below is real but benign: the
         * loser of that race joins a huddle one over its limit, which is a
         * product ceiling rather than a security boundary.
         */
        if ($live !== null) {
            $this->authorize('join', $live);
        } else {
            $this->authorize('create', [Huddle::class, $channel]);
        }

        $huddle = $joinHuddle->handle($channel, $request->user());

        return response()->json(
            $this->connection->for($huddle, $request->user(), $request),
        );
    }

    /**
     * Hang up.
     *
     * Answers 200 with a null huddle when there was nothing to leave. Hanging up
     * is announced by a button, by the page unloading, and by the tab closing —
     * all three can fire for one departure, and the second and third arriving to
     * find the call already over is the ordinary case, not an error.
     */
    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        LeaveHuddle $leaveHuddle,
    ): JsonResponse {
        $huddle = Huddle::query()->live()->forContainer($channel)->first();

        if ($huddle === null) {
            return response()->json(['huddle' => null]);
        }

        $this->authorize('leave', $huddle);

        $leaveHuddle->handle($huddle, $request->user());

        /*
         * Null rather than the huddle's remaining state. The caller is leaving,
         * so what it needs back is "you are out"; whether the call continues
         * without them reaches them over the container's broadcast like it
         * reaches everybody else.
         */
        return response()->json(['huddle' => null]);
    }
}
