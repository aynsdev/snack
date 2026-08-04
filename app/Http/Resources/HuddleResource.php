<?php

namespace App\Http\Resources;

use App\Models\Huddle;
use App\Models\HuddleParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A huddle and who is currently in it.
 *
 * # Viewer-independent on purpose
 *
 * Unlike {@see ChannelResource}, nothing here is computed from the person
 * asking. That is what lets the identical payload be the body of a broadcast —
 * see {@see App\Events\HuddleChanged} — where there is no viewer at all, and it
 * means a queued worker cannot accidentally serialize one member's answer and
 * send it to the whole channel.
 *
 * Whether the *viewer* is in the huddle is something the client already knows
 * from its own connection state, so it is not carried here.
 *
 * # No token
 *
 * A join token is minted per person, per request, and is a credential. It is
 * returned by the join endpoint alone and deliberately has no place in a shape
 * that gets broadcast to everybody looking at the channel.
 *
 * Mirrors the `Huddle` type in resources/js/types/snack.ts.
 *
 * @mixin Huddle
 */
class HuddleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // The opaque public id, which is also the LiveKit room name. The
            // primary key never leaves the server.
            'id' => $this->public_id,

            /*
             * Which channel or DM this huddle belongs to, in the same shape
             * {@see ContainerSummary} uses — the client routes the event to the
             * right container by reading it, and one shape for "where does this
             * live" means it cannot learn two.
             */
            'container' => $this->isInChannel()
                ? ['kind' => 'channel', 'id' => $this->channel_id]
                : ['kind' => 'conversation', 'id' => $this->conversation?->public_id],

            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'is_live' => $this->isLive(),

            /*
             * The roster as Snack knows it, which is what someone who has *not*
             * joined sees in the huddle bar. Once connected, the client renders
             * tiles from LiveKit's own participant list instead — that one is
             * authoritative for who can currently be heard, and it updates
             * without a round trip.
             */
            'participants' => $this->activeParticipants
                ->map(fn (HuddleParticipant $participant): array => (new UserResource($participant->user))->resolve($request))
                ->values()
                ->all(),
        ];
    }
}
