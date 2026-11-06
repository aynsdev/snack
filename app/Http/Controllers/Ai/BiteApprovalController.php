<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteBot;
use App\Services\BiteSummons;
use App\Support\BiteCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Models\Conversation as AgentConversation;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The button on Bite's "shall I create this?" card.
 *
 * Bite pauses before the tools that change a board — see
 * {@see App\Ai\Tools\CreateTask} and {@see App\Ai\Tools\UpdateTask} — and the paused call sits in the SDK's
 * `agent_conversations` tables until somebody decides. This resumes it.
 *
 * # Three separate checks, none of which replaces another
 *
 * *Can this person see the message?* The ordinary message policy, because the
 * card is part of a message and a card in a channel somebody was removed from
 * must 404 like the rest of it.
 *
 * *Is this their approval to give?* The stored agent conversation belongs to
 * whoever asked. The SDK's `continue()` deliberately does not check this — its
 * documentation says so — which means anybody who could see the card could
 * otherwise approve somebody else's pending action by posting its id.
 *
 * *May they actually do the thing?* Checked last, inside the tool, against the
 * asker. The click is consent, not authority: rights can be taken away between
 * Bite proposing a card and the button being pressed.
 *
 * # Why the decision is only yes or no
 *
 * The arguments that run are the ones the SDK stored with the paused call.
 * Nothing posted here can alter them, so a tampered request can decline
 * something or agree to it, and cannot turn "create a task on ENG" into
 * anything else.
 */
class BiteApprovalController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        Message $message,
        BiteBot $bots,
        BiteSummons $summons,
        BiteAttachments $attachments,
    ): JsonResponse {
        Gate::authorize('view', $message);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        $card = $message->metadata['bite'] ?? null;
        $user = $request->user();

        abort_if(! is_array($card) || ($card['state'] ?? null) !== 'pending', Response::HTTP_NOT_FOUND);
        abort_unless($summons->available(), Response::HTTP_CONFLICT, 'Bite is not enabled.');

        $conversationId = (string) ($card['conversation_id'] ?? '');

        abort_unless($this->ownedBy($conversationId, $user->id), Response::HTTP_NOT_FOUND);

        $container = $message->container();
        $bot = $bots->existingAccountFor($workspace);

        abort_if($container === null || $bot === null, Response::HTTP_NOT_FOUND);

        $approved = $validated['decision'] === 'approve';

        $context = new BiteContext(
            workspace: $workspace,
            user: $user,
            container: $container,
            /*
             * The person's message that asked — never Bite's own card message,
             * which would make the transcript start from the pause. A resume
             * replays the stored agent conversation rather than the room, so
             * this does not change what the model reads; it is what lets the
             * tool that now runs take that message's files onto the task.
             */
            message: $this->requestMessage($card, $message, $user),
        );

        // Built before anything is written, so a card with nothing pending on
        // it 404s rather than being marked decided and then refused.
        $decisions = $this->decisions($card, $approved);

        /*
         * Marked resolved before the model is asked, not after.
         *
         * Resuming is a chain of provider round trips that can take a while and
         * can fail. If the card still said "pending" throughout, a second click
         * — or an impatient reload and a third — would submit the same decision
         * against a tool call the SDK has already consumed, which it answers
         * with an ApprovalMismatchException rather than by doing nothing.
         */
        $message->metadata = BiteCard::resolved(
            $message->metadata ?? [],
            $approved ? 'approved' : 'rejected',
        );
        $message->saveQuietly();

        try {
            $response = (new Bite($context, $bot, $attachments))
                ->continue($conversationId, as: $user)
                ->prompt($decisions);

            $text = trim((string) $response);
        } catch (Throwable $exception) {
            report($exception);

            $text = $approved
                ? 'I could not finish that — something went wrong on my side. Nothing was created.'
                : '';
        }

        if ($text !== '') {
            /*
             * Bite's account for what it did goes out as its own message, which
             * broadcasts and lands in the timeline like anything else. The card
             * is not rewritten to carry it: the outcome of a decision is a
             * sentence, and a sentence belongs in the conversation rather than
             * folded into a control that has finished its job.
             */
            $bots->post(
                container: $container,
                bot: $bot,
                body: $text,
                parent: $message->parent_message_id !== null ? $message : null,
                attachments: $attachments->drain(),
            );
        }

        /*
         * The updated card comes back in the response, the same shape the
         * standup form uses: this message lives in the timeline's own JSON
         * store rather than in Inertia props, and it was saved quietly — so
         * nothing else is going to tell the client the buttons are spent.
         */
        return response()->json([
            'bite' => $message->metadata['bite'],
        ]);
    }

    /**
     * One answer for every call the card was showing.
     *
     * Built from the ids stored on the card rather than from anything posted,
     * which is what keeps a tampered request from approving a call the person
     * was never shown. `Decisions::from([])` is not an option: an empty set
     * throws, so "approve whatever is pending" has to be spelled out as the
     * specific pending things.
     *
     * `approveRemaining` / `rejectRemaining` then covers any call the SDK still
     * considers open that the card did not list — a mismatch that should not
     * happen, and whose alternative is an ApprovalMismatchException rather than
     * a sensible default.
     *
     * A rejection carries a reason so the model can acknowledge it rather than
     * stopping mid-sentence.
     *
     * @param  array<string, mixed>  $card
     */
    private function decisions(array $card, bool $approved): Decisions
    {
        /** @var array<string, Decision> $decisions */
        $decisions = [];

        foreach (is_array($card['approvals'] ?? null) ? $card['approvals'] : [] as $approval) {
            $id = is_array($approval) ? ($approval['id'] ?? null) : null;

            if (is_string($id) && $id !== '') {
                $decisions[$id] = $approved
                    ? Decision::approve()
                    : Decision::reject('The person declined.');
            }
        }

        abort_if($decisions === [], Response::HTTP_NOT_FOUND);

        return $approved
            ? Decisions::from($decisions)->approveRemaining()
            : Decisions::from($decisions)->rejectRemaining('The person declined.');
    }

    /**
     * The message the card was answering, if it is still there and was written
     * by the person deciding, in the card's own channel or chat.
     *
     * The id comes from the card, which Snack wrote; it is re-checked anyway,
     * because what the tool does with it — copy its files onto a card — must
     * only ever reach the decider's own words.
     *
     * @param  array<string, mixed>  $card
     */
    private function requestMessage(array $card, Message $cardMessage, User $user): ?Message
    {
        $id = $card['request_message_id'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        return Message::query()
            ->with('attachments')
            ->whereKey($id)
            ->where('workspace_id', $cardMessage->workspace_id)
            ->where('sender_id', $user->id)
            ->where('channel_id', $cardMessage->channel_id)
            ->where('conversation_id', $cardMessage->conversation_id)
            ->first();
    }

    /**
     * Whether this stored agent conversation is the requesting user's.
     *
     * Matched on the morph type as well as the id, because a `Team` and a `User`
     * can share a primary key and the participant columns are polymorphic.
     */
    private function ownedBy(string $conversationId, int $userId): bool
    {
        if ($conversationId === '') {
            return false;
        }

        return AgentConversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', (new User)->getMorphClass())
            ->where('participant_id', $userId)
            ->exists();
    }
}
