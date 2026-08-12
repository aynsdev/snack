<?php

namespace App\Actions\Standups;

use App\Actions\Messages\UpdateMessage;
use App\Models\StandupResponse;
use App\Services\StandupBot;

/**
 * Puts one person's answers in the channel, the moment they give them.
 *
 * # Why not wait for the summary
 *
 * Because the value of a standup is almost entirely in how soon it is read. The
 * person who says at 09:31 that they are blocked on a migration is asking for
 * help; holding that until the last teammate replies — or until the close window
 * expires four hours later — delivers the request after the morning it was about.
 * The run's summary still posts at close, but as a roll-up of who spoke rather
 * than as the first time anybody could have heard them.
 *
 * # Skips are not posted
 *
 * "Nothing to report" is worth knowing and is not worth a message. It goes in
 * the summary's tail line, where four quiet people cost one line rather than
 * four. The same applies to a response whose answers were all blank.
 */
class PostStandupAnswer
{
    public function __construct(
        private readonly StandupBot $bot,
        private readonly UpdateMessage $update,
    ) {}

    public function handle(StandupResponse $response): ?StandupResponse
    {
        $pairs = $response->answerPairs();

        if ($pairs === []) {
            return null;
        }

        $standup = $response->run->standup;
        $body = $this->body($response);

        /*
         * A second submission edits the message it already posted.
         *
         * Answering is not structurally once-only — the policy lets a response be
         * submitted again while the run is open — so without this, a retry or a
         * reply that races the card would put the same person's update in the
         * channel twice, and the channel has no way to tell which one is current.
         */
        if ($response->answerMessage !== null) {
            $this->update->handle($response->answerMessage, $body);

            return $response;
        }

        $bot = $this->bot->accountFor($standup->workspace);

        /*
         * Checked here as well as at install and at close: an admin can remove
         * the bot from the channel, and a standup can be moved to another one.
         * A message authored by somebody the channel's member list does not
         * contain reads as a bug to everybody who sees it.
         */
        $this->bot->joinChannel($standup->channel, $bot);

        $message = $this->bot->post(
            container: $standup->channel,
            bot: $bot,
            body: $body,
        );

        $response->forceFill(['answer_message_id' => $message->id])->save();

        return $response;
    }

    /**
     * What the channel reads.
     *
     * The person's name leads, because the bot is the author of every one of
     * these and a column of identical avatars is unreadable without it. The
     * standup's name and date follow on the same line rather than above it: a
     * team with a morning standup and a Friday retro needs to tell them apart,
     * and it is not worth a second line to do so.
     */
    private function body(StandupResponse $response): string
    {
        $run = $response->run;
        $standup = $run->standup;

        $lines = ["**{$response->user->name}** — {$standup->name} · {$run->dateLabel()}"];

        foreach ($response->answerPairs() as $pair) {
            $lines[] = '';
            $lines[] = "_{$pair['question']}_";
            $lines[] = $pair['answer'];
        }

        return implode("\n", $lines);
    }
}
