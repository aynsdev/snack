<?php

namespace App\Actions\Standups;

use App\Enums\StandupResponseState;
use App\Enums\StandupRunState;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Services\StandupBot;
use Illuminate\Support\Collection;

/**
 * Writes a run up and posts it to the channel.
 *
 * Called from two places for the same reason: the moment the last person
 * answers, and when the close window expires with people still outstanding.
 * Whichever gets there first, the run closes exactly once — the `closed_at`
 * guard below is what stops a submission landing in the same second as the
 * sweep from posting the summary twice.
 *
 * # What a summary says about the people who did not answer
 *
 * It names them. Not to shame anybody, but because the alternative is a summary
 * that quietly shrinks: a standup with two of five replies looks like a team of
 * two, and the reader has no way to tell that from a team of five where three
 * people were in a workshop. Naming who is outstanding is what makes the number
 * of answers mean something.
 */
class CloseStandup
{
    public function __construct(private readonly StandupBot $bot) {}

    public function handle(StandupRun $run): StandupRun
    {
        if (! $run->isOpen()) {
            return $run;
        }

        $standup = $run->standup;

        $responses = $run->responses()
            ->with('user')
            ->get()
            ->sortBy(fn (StandupResponse $response): string => $response->user->name)
            ->values();

        /*
         * "Somebody dealt with this", which includes the person who said they
         * had nothing to report. Filtering on answers instead would drop a
         * standup where the whole team is heads-down on one thing and every
         * reply was a skip — which is a real morning, and one the channel is
         * entitled to be told about rather than left to guess at.
         */
        $engaged = $responses->reject(
            fn (StandupResponse $response): bool => $response->state === StandupResponseState::Pending,
        );

        /*
         * Claimed before the message is posted.
         *
         * The stamp is what makes closing idempotent, and the post is the part
         * that cannot be undone — so the order has to be "claim, then post". The
         * other way round, two callers arriving together would both find the run
         * open, both post, and the second stamp would overwrite the first
         * summary's id, leaving a message in the channel that nothing points at.
         */
        $run->forceFill([
            'state' => StandupRunState::Completed,
            'closed_at' => now(),
        ])->save();

        /*
         * A standup nobody answered posts nothing. The channel already knows
         * what silence looks like, and a daily message announcing it is how a
         * useful app becomes one people mute.
         */
        if ($engaged->isEmpty()) {
            return $run;
        }

        $bot = $this->bot->accountFor($standup->workspace);

        /*
         * Checked here and not only when the standup was created.
         *
         * Installing a standup puts the bot in its channel, but that is not the
         * only way a bot can end up outside the channel it reports to: an admin
         * can remove it from the member list, and a standup moved to another
         * channel is only as joined as the edit that moved it. Posting a summary
         * authored by somebody the channel's own member list does not contain is
         * the sort of thing that reads as a bug to everybody who sees it.
         *
         * A `firstOrCreate` on close, which is once a day per standup.
         */
        $this->bot->joinChannel($standup->channel, $bot);

        $summary = $this->bot->post(
            container: $standup->channel,
            bot: $bot,
            body: $this->body($run, $responses),
        );

        $run->forceFill(['summary_message_id' => $summary->id])->save();

        return $run;
    }

    /**
     * The message the channel reads.
     *
     * One message rather than one per person: a standup is a thing the team
     * reads in a minute, and five messages would push four of them off the
     * screen and light the channel up five times.
     *
     * @param  Collection<int, StandupResponse>  $responses
     */
    private function body(StandupRun $run, Collection $responses): string
    {
        $standup = $run->standup;

        $lines = ["**{$standup->name}** — {$run->dateLabel()}"];

        foreach ($responses as $response) {
            $pairs = $response->answerPairs();
            $name = $response->user->name;

            if ($pairs === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = "**{$name}**";

            foreach ($pairs as $pair) {
                $lines[] = "_{$pair['question']}_";
                $lines[] = $pair['answer'];
            }
        }

        $skipped = $this->names($responses, StandupResponseState::Skipped);
        $waiting = $this->names($responses, StandupResponseState::Pending);

        /*
         * Both tails are single lines rather than sections. "Nothing to report"
         * is information, but it is not worth the same vertical space as an
         * answer, and a standup where four people are quiet should not be four
         * paragraphs of nothing.
         */
        if ($skipped !== []) {
            $lines[] = '';
            $lines[] = '_Nothing to report: '.$this->join($skipped).'._';
        }

        if ($waiting !== []) {
            $lines[] = '';
            $lines[] = '_No answer yet from '.$this->join($waiting).'._';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, StandupResponse>  $responses
     * @return array<int, string>
     */
    private function names(Collection $responses, StandupResponseState $state): array
    {
        return $responses
            ->filter(fn (StandupResponse $response): bool => $response->state === $state
                && $response->answerPairs() === [])
            ->map(fn (StandupResponse $response): string => $response->user->name)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     */
    private function join(array $names): string
    {
        return collect($names)->join(', ', ' and ');
    }
}
