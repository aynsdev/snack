<?php

namespace App\Actions\Standups;

use App\Models\StandupRun;
use App\Services\StandupBot;
use App\Support\StandupCard;

/**
 * Nudges the people who have not answered yet.
 *
 * # Once, and only into the thread that already has the question
 *
 * `reminded_at` is stamped whether or not anybody was actually chased, so a run
 * cannot be reminded twice by two sweeps arriving together. And the nudge is a
 * short line in the same conversation as the prompt — not a new thread, not a
 * repeat of the questions — because the thing being reminded of is three
 * centimetres up the screen. A second copy of the questions would make the
 * conversation a wall of the same text and give the person two cards, only one
 * of which does anything.
 */
class RemindStandup
{
    public function __construct(private readonly StandupBot $bot) {}

    /**
     * @return int How many people were nudged.
     */
    public function handle(StandupRun $run): int
    {
        if (! $run->isOpen() || $run->reminded_at !== null) {
            return 0;
        }

        // Claimed first, for the same reason closing is: the stamp is what makes
        // this run-once, and the messages below cannot be taken back.
        $run->forceFill(['reminded_at' => now()])->save();

        $standup = $run->standup;

        $outstanding = $run->responses()
            ->outstanding()
            ->with('user')
            ->get();

        foreach ($outstanding as $response) {
            $response->setRelation('run', $run);

            $this->bot->directMessage(
                workspace: $standup->workspace,
                recipient: $response->user,
                body: $this->nudge($run),
                /*
                 * The nudge carries its own card. Being reminded and then having
                 * to scroll back up to the question to act on it is how a
                 * reminder becomes something people dismiss. Both cards point at
                 * the same response, and submitting retires both — see
                 * {@see SubmitStandupResponse::retirePrompt()}.
                 */
                metadata: ['standup' => StandupCard::for($response)],
            );
        }

        return $outstanding->count();
    }

    /**
     * Deliberately says when it closes rather than asking again.
     *
     * A deadline is actionable — it tells somebody whether to answer now or
     * whether they have missed it — where "please respond" is a second copy of
     * a question they have already read and decided about.
     */
    private function nudge(StandupRun $run): string
    {
        $standup = $run->standup;
        $closes = $run->closesAt()->setTimezone($standup->timezone)->format('H:i');

        return "Still waiting on your **{$standup->name}** for {$run->dateLabel()}. "
            ."I post to #{$standup->channel->slug} at {$closes}.";
    }
}
