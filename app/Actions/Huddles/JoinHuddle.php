<?php

namespace App\Actions\Huddles;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts somebody into a container's huddle, starting one if there is not already
 * a live one.
 *
 * # Why starting and joining are one action
 *
 * From the product's side they are one button. Pressing "Huddle" in a channel
 * means "put me in the call here", and whether that call already exists is an
 * implementation detail the person pressing it neither knows nor should have to.
 * Splitting them would also make the interesting case — two people pressing it
 * within the same second — a client-side decision, and the client cannot make it
 * correctly.
 *
 * # Why the database decides who started it
 *
 * The obvious implementation is a race, in exactly the way
 * {@see App\Actions\Conversations\FindOrCreateConversation} is: both callers look,
 * both find nothing, both insert, and neither knows the other existed. The
 * result would be two live huddles in one channel — the worst possible outcome,
 * because both work perfectly and each person is alone in a room wondering why
 * nobody else can hear them.
 *
 * `huddles_one_live_per_channel` is what actually rules it out, so the insert is
 * allowed to fail and a violation is read as "somebody else started it a
 * millisecond ago", which is the correct answer rather than an error.
 *
 * Authorization is the caller's responsibility. The container arrives as a
 * resolved model, never as a client-supplied identifier.
 */
class JoinHuddle
{
    public function __construct(
        private readonly AnnounceHuddle $announce,
        private readonly LeaveHuddle $leave,
    ) {}

    public function handle(Channel|Conversation $container, User $user): Huddle
    {
        [$huddle, $started] = $this->findOrStart($container, $user);

        /*
         * Out of any other call first. One huddle at a time per person, which is
         * not merely a product rule: LiveKit disconnects the older connection
         * when the same identity appears twice, so a roster that still listed
         * them elsewhere would be describing a connection that no longer exists.
         *
         * Before admitting them rather than after, so the brief overlap is
         * "in neither" rather than "in both" — the latter is the state that
         * makes "am I already in a huddle?" answer ambiguously.
         */
        $this->leave->fromAll($user, except: $huddle);

        $admitted = $this->admit($huddle, $user);

        $huddle->unsetRelation('participants')->unsetRelation('activeParticipants');

        /*
         * A huddle that was just started is announced as a lifecycle change —
         * the sidebar dot appears for the whole channel, and in a conversation
         * the other participants' phones ring — while joining an existing one
         * only moves the roster. The starter is left out of both: they are in
         * the call, they know.
         *
         * Neither fires when somebody who was already in the huddle asks again,
         * which is what a reconnecting client does. Nothing changed, so nothing
         * is broadcast.
         */
        if ($started) {
            $this->announce->started($huddle, $user);
        } elseif ($admitted) {
            $this->announce->roster($huddle);
        }

        return $huddle;
    }

    /**
     * The container's live huddle, or a brand new one.
     *
     * @return array{0: Huddle, 1: bool} The huddle, and whether this call
     *                                   started it.
     */
    private function findOrStart(Channel|Conversation $container, User $user): array
    {
        $existing = $this->liveHuddleIn($container);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [$this->attempt(fn (): Huddle => Huddle::create([
                // Taken from the container, never from the request: a huddle
                // must not be able to name a workspace other than its own.
                'workspace_id' => $container->workspace_id,
                'channel_id' => $container instanceof Channel ? $container->id : null,
                'conversation_id' => $container instanceof Conversation ? $container->id : null,
                'started_by' => $user->id,
                'started_at' => now(),
            ])), true];
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Lost the race. The huddle the winner inserted is the one this
             * person meant to join, so re-read it — re-throwing would surface an
             * error for a request that in fact has a perfectly good answer.
             */
            return [$this->liveHuddleIn($container) ?? throw $exception, false];
        }
    }

    /**
     * Run an insert that is *expected* to sometimes violate a unique index,
     * without poisoning whatever transaction the caller is already in.
     *
     * PostgreSQL aborts the entire transaction on a constraint violation and
     * refuses every subsequent statement with "current transaction is aborted"
     * — so catching the exception is not enough on its own. Outside a
     * transaction that does not matter; inside one, the recovery re-read is the
     * next statement and it fails too, turning a handled race into a 500.
     *
     * Laravel's nested `transaction()` issues a SAVEPOINT and rolls back to it,
     * which confines the damage to this one statement. This is not a
     * hypothetical: `RefreshDatabase` wraps every test in a transaction, so the
     * un-savepointed version passed in production and failed in the test suite.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $insert
     * @return TResult
     */
    private function attempt(Closure $insert): mixed
    {
        return DB::transaction($insert);
    }

    private function liveHuddleIn(Channel|Conversation $container): ?Huddle
    {
        return Huddle::query()
            ->live()
            ->forContainer($container)
            ->with(['channel', 'conversation', 'activeParticipants.user'])
            ->first();
    }

    /**
     * Add the participant row, tolerating the one that is already there.
     *
     * @return bool Whether this call is what put them in.
     */
    private function admit(Huddle $huddle, User $user): bool
    {
        try {
            $this->attempt(fn (): HuddleParticipant => HuddleParticipant::create([
                'huddle_id' => $huddle->id,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            /*
             * `huddle_participants_one_live_per_user` caught a double click, or
             * a client re-announcing itself after a reconnect. They are already
             * in the huddle, which is the state the request was asking for — so
             * this succeeds, and reports that nothing changed so the caller does
             * not broadcast a roster that is identical to the last one.
             */
            return false;
        }
    }
}
