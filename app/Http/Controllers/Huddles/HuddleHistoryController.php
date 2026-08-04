<?php

namespace App\Http\Controllers\Huddles;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\UserResource;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Huddles that have happened, newest first.
 *
 * A huddle leaves no message behind — that is the point of it — so without this
 * a call you missed by ten minutes is indistinguishable from one that never
 * happened. This is the record: where it was, when, how long it ran, and who
 * was on it.
 *
 * # What it is not
 *
 * Not a recording and not a transcript. Snack never holds the media; the media
 * server does, and only for as long as a call is live. This lists the fact of a
 * huddle, which is all Snack knows.
 *
 * # Scoping
 *
 * Rows are filtered to containers the viewer is actually in — channels they have
 * joined and conversations they participate in. A huddle in a public channel
 * somebody never joined is not part of their history, and one in a DM between
 * two other people must never be: the participant list alone would say who is
 * talking to whom.
 */
class HuddleHistoryController extends Controller
{
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $user = $request->user();

        $huddles = Huddle::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query
                ->whereHas('channel', fn ($channel) => $channel->whereMember($user))
                ->orWhereHas('conversation', fn ($conversation) => $conversation->visibleTo($user)),
            )
            /*
             * `participants` rather than `activeParticipants`: this is the
             * record of who was on the call, and everybody has left a finished
             * one. The live rows at the top of the list are the exception that
             * would look empty if this asked who is still there.
             */
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

        return Inertia::render('huddles/index', [
            'huddles' => $huddles
                ->map(fn (Huddle $huddle): array => [
                    'id' => $huddle->public_id,
                    'container' => ContainerSummary::forContainer($huddle->container()),
                    'started_at' => $huddle->started_at->toIso8601String(),
                    'ended_at' => $huddle->ended_at?->toIso8601String(),
                    'is_live' => $huddle->isLive(),

                    /*
                     * Computed here rather than in the browser, so a clock that
                     * is a few minutes out cannot report a call as having run
                     * for minus three. A live huddle has no duration yet — it is
                     * still accruing one — and says so with null.
                     */
                    'duration_seconds' => $huddle->ended_at === null
                        ? null
                        : $huddle->started_at->diffInSeconds($huddle->ended_at),

                    'started_by' => $huddle->starter === null
                        ? null
                        : (new UserResource($huddle->starter))->resolve($request),

                    'participants' => $huddle->participants
                        ->map(fn (HuddleParticipant $participant): ?User => $participant->user)
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
