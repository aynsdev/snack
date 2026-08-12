<?php

namespace App\Actions\Standups;

use App\Enums\StandupResponseState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\StandupResponse;
use App\Models\User;
use App\Services\StandupBot;
use App\Support\StandupReply;

/**
 * A message typed into the standup DM, taken as the answer.
 *
 * # Why this exists at all
 *
 * The card is the better way to answer and it is not the way everybody tries.
 * The prompt itself says "Answer here and I will post it to #channel", the
 * conversation looks exactly like a conversation, and on a phone the reply box
 * is the obvious thing on the screen. Before this, a person who typed their
 * update into it was answered by silence: the channel never heard from them, the
 * nudge arrived an hour later, and the summary named them as missing — with
 * their answer sitting in the DM the whole time.
 *
 * # What it is careful not to become
 *
 * A conversational bot. Nothing here waits for a second message, asks a
 * follow-up, or holds state between replies. One message in, one submission out,
 * and everything that cannot be parsed is still kept as an answer rather than
 * rejected — see {@see StandupReply} for the ladder of guesses and why it stops
 * where it does.
 *
 * # Why the confirmation is a message and not a flag on the response
 *
 * Because the person needs to see something happen. The card above them retires
 * to its answered state — that is broadcast now, for exactly this path — but a
 * card changing quietly halfway up the scrollback is not an acknowledgement. A
 * reply that says where the update went is.
 */
class AnswerStandupFromReply
{
    public function __construct(
        private readonly SubmitStandupResponse $submit,
        private readonly StandupBot $bot,
    ) {}

    /**
     * @param  Message  $message  The message just sent by $sender, already persisted.
     * @return StandupResponse|null The response this answered, if it answered one.
     */
    public function handle(Conversation $conversation, User $sender, Message $message): ?StandupResponse
    {
        /*
         * Bots do not answer standups, and this is the guard that keeps the
         * confirmation below from being read as an answer to itself.
         */
        if ($sender->is_bot) {
            return null;
        }

        $response = $this->outstandingFor($conversation, $sender);

        if ($response === null || ! $response->run->isOpen()) {
            return null;
        }

        /*
         * A thread reply counts only when the thread is the standup's own.
         *
         * Replying under the prompt is a reasonable way to answer and people do
         * it, but a thread hanging off some unrelated message in the same DM is
         * a different conversation, and swallowing it as a standup answer is the
         * failure the card design exists to avoid.
         */
        if ($message->parent_message_id !== null && ! $this->threadBelongsTo($message, $response)) {
            return null;
        }

        $reply = StandupReply::parse(
            $message->body ?? '',
            $this->submit->questionsAsked($response),
        );

        /*
         * An attachment with no words, or whitespace. Not an answer, and not an
         * error either — the person may well be about to type one, and a bot
         * that scolds them for sending a screenshot first is worse than a bot
         * that says nothing.
         */
        if ($reply->isEmpty()) {
            return null;
        }

        $response = $this->submit->handle($response, $reply->answers, $reply->skipped);

        $this->confirm($conversation, $response);

        return $response;
    }

    /**
     * This person's unanswered response in this DM, if they have one.
     *
     * `submitted_at IS NULL` is stated alongside the state it implies, because
     * that is the predicate on `standup_responses_pending_index` — without it
     * this becomes a scan on a table with a row per person per morning, run on
     * every direct message anybody sends.
     */
    private function outstandingFor(Conversation $conversation, User $sender): ?StandupResponse
    {
        return StandupResponse::query()
            ->where('user_id', $sender->id)
            ->whereNull('submitted_at')
            ->where('state', StandupResponseState::Pending)
            ->where('conversation_id', $conversation->id)
            ->with(['run.standup.channel', 'promptMessage'])
            ->latest('id')
            ->first();
    }

    /**
     * Whether a threaded reply hangs off one of this response's own cards.
     */
    private function threadBelongsTo(Message $message, StandupResponse $response): bool
    {
        $root = Message::query()->find($message->parent_message_id);

        return data_get($root?->metadata, 'standup.response_id') === $response->id;
    }

    /**
     * Tell them where it went.
     *
     * Skips say nothing about the channel on purpose: a skip is not posted
     * there, only counted in the summary's tail, and promising otherwise would
     * be a lie the person could check.
     */
    private function confirm(Conversation $conversation, StandupResponse $response): void
    {
        $standup = $response->run->standup;
        $bot = $this->bot->accountFor($standup->workspace);

        $body = $response->state === StandupResponseState::Skipped
            ? 'Noted — nothing to report today.'
            : "Thanks — posted to #{$standup->channel->slug}.";

        $this->bot->post(container: $conversation, bot: $bot, body: $body);
    }
}
