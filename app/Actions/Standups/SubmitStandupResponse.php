<?php

namespace App\Actions\Standups;

use App\Enums\StandupResponseState;
use App\Events\MessageUpdated;
use App\Models\Message;
use App\Models\StandupResponse;
use Illuminate\Support\Facades\DB;

/**
 * Records one person's answers, and writes them back onto the prompt.
 *
 * # Why the questions come from the prompt and not from the standup
 *
 * The prompt message carries the questions it was sent with. Reading the
 * standup's *current* questions here would file today's answers under whatever
 * an admin edited them to five minutes ago — so the person who answered "yes"
 * to one question is shown answering a different one. The snapshot on the
 * prompt is the only record of what was actually asked, so it is the one used.
 *
 * # Why the prompt message is rewritten
 *
 * The card renders from the message's own metadata, which is what lets a DM draw
 * it without a request per message. The cost of that is that the message has to
 * be told when it stops being a question — otherwise the "Answer" button is
 * still sitting in the conversation tomorrow morning, offering to reopen
 * something that is already in the channel.
 */
class SubmitStandupResponse
{
    public function __construct(
        private readonly CloseStandup $close,
        private readonly PostStandupAnswer $post,
    ) {}

    /**
     * @param  array<int, string>  $answers  Aligned with the prompt's questions,
     *                                       by position. Extra entries are dropped and missing ones are
     *                                       treated as blank — the client's idea of the question list can
     *                                       only ever be as fresh as the page it was rendered on.
     * @param  bool  $skipped  "Nothing from me today", which is a different fact
     *                         from having said nothing at all — see {@see StandupResponseState}.
     */
    public function handle(StandupResponse $response, array $answers, bool $skipped = false): StandupResponse
    {
        $questions = $this->questionsAsked($response);

        $pairs = [];

        foreach ($questions as $index => $question) {
            $answer = trim((string) ($answers[$index] ?? ''));

            if ($answer !== '') {
                $pairs[] = ['question' => $question, 'answer' => $answer];
            }
        }

        /*
         * An empty submission is a skip. The alternative — refusing it — makes
         * "I have nothing to report" an error message, and the person who has
         * nothing to report is exactly the one the channel most needs to hear
         * from, because otherwise they are indistinguishable from somebody who
         * is ill.
         */
        $state = ($skipped || $pairs === [])
            ? StandupResponseState::Skipped
            : StandupResponseState::Submitted;

        $retired = DB::transaction(function () use ($response, $pairs, $state): array {
            $response->forceFill([
                'answers' => $pairs,
                'state' => $state,
                'submitted_at' => now(),
            ])->save();

            return $this->retirePrompt($response);
        });

        /*
         * The retired cards are announced after the commit, for the same reason
         * the channel post below is: a broadcast cannot be rolled back.
         *
         * They are announced at all because answering is no longer something
         * only the card can do. A reply typed into the DM is submitted by
         * App\Actions\Standups\AnswerStandupFromReply, and the person who typed
         * it holds no handle on the response — without this, the form would sit
         * there still offering to answer a standup that is already in the
         * channel, until they happened to reload.
         */
        foreach ($retired as $card) {
            MessageUpdated::dispatch($card);
        }

        /*
         * Straight into the channel, ahead of any summary.
         *
         * Everything below this point is outside the transaction, and for one
         * reason: these post messages, and a message that has broadcast cannot
         * be rolled back.
         */
        $this->post->handle($response);

        /*
         * The run is written up the moment the last person answers, rather than
         * at the close window. A standup everybody finished at 09:34 is worth
         * reading at 09:34; holding the roll-up until lunchtime because that is
         * when the timer expires is the difference between a tool the team uses
         * and a report they find later.
         */
        $run = $response->run;

        if ($run->isOpen() && $run->isFullyAnswered()) {
            $this->close->handle($run);
        }

        return $response;
    }

    /**
     * The questions this person was actually asked.
     *
     * Falls back to the standup's current wording only when the prompt is gone —
     * a deleted DM, or a response created by something other than the ask path.
     * Not a correctness path so much as a "never render an answer with no
     * question attached to it" one.
     *
     * Public because a typed reply has to be split against the same snapshot
     * this then aligns to — see {@see AnswerStandupFromReply}. Two readings of
     * "which questions were asked" is exactly the disagreement that files an
     * answer under the wrong question.
     *
     * @return array<int, string>
     */
    public function questionsAsked(StandupResponse $response): array
    {
        $asked = data_get($response->promptMessage?->metadata, 'standup.questions');

        if (is_array($asked) && $asked !== []) {
            return collect($asked)
                ->filter(fn (mixed $question): bool => is_string($question))
                ->values()
                ->all();
        }

        return $response->run->standup->questionList();
    }

    /**
     * Mark every card for this response answered, so none of them goes on
     * offering the form.
     *
     * # Why every card and not just the prompt
     *
     * The reminder carries a card too — being nudged and then having to scroll
     * back up to the question is the kind of thing that makes people stop
     * answering — so one response can have two live cards in the same
     * conversation. Retiring only the one named by `prompt_message_id` would
     * leave the nudge's button sitting there, reopening a standup that is
     * already in the channel.
     *
     * Scoped to the conversation before the JSON test, which is what keeps this
     * an index lookup on a table with every message in the workspace in it: the
     * cards for a response are all in one DM by construction.
     *
     * `saveQuietly` here and the broadcast raised by the caller, after the
     * commit — a message that has announced itself cannot be un-announced by a
     * rollback.
     *
     * @return array<int, Message> The cards, for the caller to announce.
     */
    private function retirePrompt(StandupResponse $response): array
    {
        if ($response->conversation_id === null) {
            return [];
        }

        $cards = Message::query()
            ->where('conversation_id', $response->conversation_id)
            ->where('metadata->standup->response_id', $response->id)
            ->get();

        $retired = [];

        foreach ($cards as $message) {
            $metadata = $message->metadata ?? [];

            if (! isset($metadata['standup'])) {
                continue;
            }

            $metadata['standup'] = [
                ...$metadata['standup'],
                'state' => $response->state->value,
                'submitted_at' => $response->submitted_at?->toIso8601String(),
                'answers' => $response->answerPairs(),
            ];

            $message->metadata = $metadata;
            $message->saveQuietly();

            $retired[] = $message;
        }

        return $retired;
    }
}
