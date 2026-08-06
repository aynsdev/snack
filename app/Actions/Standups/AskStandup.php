<?php

namespace App\Actions\Standups;

use App\Enums\StandupResponseState;
use App\Enums\StandupRunState;
use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Models\User;
use App\Services\StandupBot;
use App\Support\StandupCard;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens today's run and asks everybody on the roster.
 *
 * # Asking is not idempotent, so the database decides
 *
 * This direct-messages every member of a channel. Running it twice would DM
 * them twice, and the scheduler wakes every minute — so "have we asked today?"
 * cannot be an inference from the clock. The unique index on `(standup_id,
 * scheduled_for)` is what actually settles it: two workers on the same minute
 * both insert, one wins, and the loser reads back the winner's row rather than
 * erroring. Checking first and then inserting is the version of this that
 * messages the whole team twice.
 *
 * # Resumable, not all-or-nothing
 *
 * Each person's row is claimed before their message is sent, and `asked_at` is
 * stamped only once everybody has been reached. A worker that dies halfway
 * leaves a run whose remaining members simply have no row yet, so the next
 * minute picks up exactly where it stopped — nobody is asked twice, and nobody
 * is silently skipped. Wrapping the whole fan-out in a transaction would give
 * the opposite: a crash on the last member would roll back the first nine
 * people's rows while their direct messages, already sent, stayed sent.
 */
class AskStandup
{
    public function __construct(private readonly StandupBot $bot) {}

    /**
     * @param  CarbonImmutable  $date  The local date whose standup this is — see
     *                                 {@see Standup::dueDate()}.
     */
    public function handle(Standup $standup, CarbonImmutable $date): StandupRun
    {
        $run = $this->openRun($standup, $date);

        if (! $run->isOpen()) {
            return $run;
        }

        /*
         * The app shows up in the channel on the morning it starts asking, not
         * only when an admin installs it — a standup created by a seeder, an
         * import, or a future bulk-provisioning path is still a standup whose
         * members deserve to see where the questions come from.
         *
         * Gated on the ask being unfinished so this is one query on the first
         * minute and on a resumed one, rather than one on every minute of the
         * catch-up window.
         */
        if ($run->asked_at === null) {
            $this->bot->joinChannel($standup->channel, $this->bot->accountFor($standup->workspace));
        }

        $asked = 0;

        foreach ($standup->participants() as $participant) {
            $asked += $this->ask($standup, $run, $participant) ? 1 : 0;
        }

        if ($run->asked_at === null) {
            $run->forceFill(['asked_at' => now()])->save();
        }

        /*
         * A standup whose channel has emptied out — everybody left, or the only
         * members left are bots — has nobody to hear from and nothing to
         * summarise. Closing it immediately keeps it out of the reminder and
         * close sweeps forever after, and posts nothing into the channel, which
         * is the correct amount of noise for "nobody was asked".
         */
        if ($asked === 0 && $run->responses()->doesntExist()) {
            $run->forceFill([
                'state' => StandupRunState::Completed,
                'closed_at' => now(),
            ])->save();
        }

        return $run;
    }

    /**
     * Today's run, created if this is the first look at it.
     */
    private function openRun(Standup $standup, CarbonImmutable $date): StandupRun
    {
        $existing = $standup->runs()
            ->where('scheduled_for', $date->toDateString())
            ->first();

        if ($existing !== null) {
            return $existing->setRelation('standup', $standup);
        }

        try {
            /*
             * The insert is wrapped in its own transaction on purpose, even
             * though it is a single statement.
             *
             * PostgreSQL aborts the *entire* transaction on a constraint
             * violation and refuses every statement after it. So a bare
             * try/catch works only when nothing else has a transaction open —
             * and the recovery read below would be the next statement, which
             * would fail too. The nested call issues a SAVEPOINT, confining the
             * rollback to this one insert and leaving the surrounding
             * transaction usable.
             *
             * Not hypothetical: `RefreshDatabase` wraps every test in a
             * transaction, so the un-savepointed version of this passes in
             * production and 500s in the suite.
             */
            $run = DB::transaction(fn (): StandupRun => $standup->runs()->create([
                'scheduled_for' => $date->toDateString(),
                'state' => StandupRunState::Collecting,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            // Lost the race. The winner's row is the answer, not an error.
            $run = $standup->runs()
                ->where('scheduled_for', $date->toDateString())
                ->first() ?? throw $exception;
        }

        return $run->setRelation('standup', $standup);
    }

    /**
     * Ask one person, unless they have already been asked.
     *
     * @return bool Whether a message was sent — false means they already had a
     *              row, which is how a resumed run skips the people it reached
     *              before it died.
     */
    private function ask(Standup $standup, StandupRun $run, User $participant): bool
    {
        $response = StandupResponse::query()->firstOrCreate(
            ['standup_run_id' => $run->id, 'user_id' => $participant->id],
            ['state' => StandupResponseState::Pending, 'answers' => []],
        );

        if (! $response->wasRecentlyCreated) {
            return false;
        }

        $response->setRelation('run', $run);

        $message = $this->bot->directMessage(
            workspace: $standup->workspace,
            recipient: $participant,
            body: $this->prompt($standup, $run),
            metadata: ['standup' => StandupCard::for($response)],
        );

        /*
         * Stamped after the send, because the card the client renders is keyed
         * on the message that carries it — and there is no message id to key on
         * until it exists. Submitting reads this back to mark the prompt
         * answered; see {@see SubmitStandupResponse}.
         */
        $response->forceFill([
            'conversation_id' => $message->conversation_id,
            'prompt_message_id' => $message->id,
        ])->save();

        return true;
    }

    /**
     * What the bot actually says.
     *
     * The questions are in the body as well as in the card. The body is what
     * search indexes, what the sidebar previews, and what a notification email
     * quotes — and it is what somebody on a client that has never heard of
     * standup cards still sees. The card is the way to answer, not the way to
     * read.
     */
    private function prompt(Standup $standup, StandupRun $run): string
    {
        $lines = [
            "**{$standup->name}** — {$run->dateLabel()}",
            '',
        ];

        foreach ($standup->questionList() as $index => $question) {
            $lines[] = ($index + 1).'. '.$question;
        }

        $lines[] = '';
        $lines[] = 'Answer here and I will post it to #'.$standup->channel->slug.'.';

        return implode("\n", $lines);
    }
}
