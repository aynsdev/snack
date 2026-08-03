<?php

namespace App\Http\Controllers\Presence;

use App\Actions\Presence\RecordPresence;
use App\Enums\PresenceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Presence\HeartbeatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The heartbeat an open tab sends to say it is still there.
 *
 * Outside the workspace prefix on purpose: presence is a property of the person,
 * not of whichever workspace they happen to be looking at. Scoping it to one
 * would mean a user in two workspaces had two answers to "are they here", and
 * the tab that lost the race would decide.
 *
 * Returns JSON rather than an Inertia redirect. This is a background request on
 * a timer — turning it into a page visit would re-render the whole workspace
 * shell once a minute, forever.
 */
class PresenceController extends Controller
{
    public function __construct(private readonly RecordPresence $presence) {}

    /**
     * Renew the claim.
     */
    public function store(HeartbeatRequest $request): JsonResponse
    {
        $this->presence->heartbeat($request->user(), $request->status());

        return response()->json([
            'presence_status' => $request->user()->presence_status->value,
        ]);
    }

    /**
     * Give the claim up, on sign-out or on a deliberately closed tab.
     *
     * Best-effort by nature: a browser that crashes or loses its network sends
     * nothing at all, which is exactly why {@see App\Console\Commands\SweepPresence}
     * exists rather than this being the only path to Offline.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->presence->offline($request->user());

        return response()->json(['presence_status' => PresenceStatus::Offline->value]);
    }
}
