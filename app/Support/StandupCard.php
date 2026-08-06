<?php

namespace App\Support;

use App\Models\StandupResponse;

/**
 * The structured block a standup direct message carries, and the client renders
 * as a form.
 *
 * # Why the message carries it instead of the client fetching it
 *
 * A DM scrolling into view has to draw its card without a request of its own —
 * a conversation with a month of standups in it would otherwise be a month of
 * requests on open — and the card has to survive a reload, which client-side
 * state would not. So it rides on the message, in `metadata`, and is rewritten
 * in place when the response is submitted. See
 * {@see App\Actions\Standups\SubmitStandupResponse}.
 *
 * Built in one place because two messages carry it — the prompt and the reminder
 * — and a card whose two versions disagreed about which response they answer is
 * a form that writes to the wrong row.
 *
 * The questions are copied in rather than referenced. They are the snapshot of
 * what was actually asked, which is the whole reason a past standup stays
 * readable after an admin rewords it.
 */
final readonly class StandupCard
{
    /**
     * @return array<string, mixed>
     */
    public static function for(StandupResponse $response): array
    {
        $run = $response->run;
        $standup = $run->standup;

        return [
            'standup_id' => $standup->id,
            'run_id' => $run->id,
            'response_id' => $response->id,
            'name' => $standup->name,
            'channel' => [
                'name' => $standup->channel->name,
                'slug' => $standup->channel->slug,
            ],
            'date' => $run->scheduled_for->toDateString(),
            'date_label' => $run->dateLabel(),
            'questions' => $standup->questionList(),
            'closes_at' => $run->closesAt()->toIso8601String(),
            'state' => $response->state->value,
            'submitted_at' => $response->submitted_at?->toIso8601String(),
            'answers' => $response->answerPairs(),
        ];
    }
}
