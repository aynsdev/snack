<?php

namespace App\Actions\Huddles;

use App\Models\Huddle;
use Illuminate\Support\Facades\DB;

/**
 * Closes a huddle.
 *
 * Reached three ways: the last participant leaves, the sweep finds a huddle that
 * has outlived everyone in it, or a channel is archived under a live call. All
 * three want the same thing — the huddle stops being live and the room stops
 * being advertised — so none of them writes `ended_at` itself.
 *
 * Nothing here disconnects anybody from LiveKit. The media server holds the
 * actual connections and will empty the room on its own timeout (see
 * `room.empty_timeout` in livekit.yaml); what this ends is Snack's advertisement
 * of the huddle, which is what everyone who has *not* joined is looking at.
 * Trying to keep the two in lockstep would mean an API call that can fail on the
 * one path that must not — and a huddle that could not be closed because the
 * media server was unreachable would be advertised forever.
 */
class EndHuddle
{
    public function __construct(private readonly AnnounceHuddle $announce) {}

    /**
     * @return bool Whether this call is what ended it. False if it was already
     *              over, so callers can avoid announcing the same thing twice.
     */
    public function handle(Huddle $huddle): bool
    {
        if (! $huddle->isLive()) {
            return false;
        }

        $endedAt = now();

        /*
         * Conditional on `ended_at` still being null, so two racing callers —
         * the last participant leaving at the same moment the sweep runs —
         * produce exactly one winner. The loser's update matches no rows and it
         * returns false rather than broadcasting a second "huddle over".
         */
        $ended = Huddle::query()
            ->whereKey($huddle->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => $endedAt]);

        if ($ended === 0) {
            return false;
        }

        /*
         * Close out anyone still shown as present. Their client may never send
         * a leave — a huddle ends precisely when clients have stopped talking to
         * us — and a participant row left open would make this huddle count
         * against its own members forever in "am I in a call somewhere?".
         */
        DB::table('huddle_participants')
            ->where('huddle_id', $huddle->id)
            ->whereNull('left_at')
            ->update(['left_at' => $endedAt, 'updated_at' => $endedAt]);

        $huddle->forceFill(['ended_at' => $endedAt])->syncOriginalAttributes(['ended_at']);
        $huddle->unsetRelation('participants')->unsetRelation('activeParticipants');

        $this->announce->lifecycle($huddle);

        return true;
    }
}
