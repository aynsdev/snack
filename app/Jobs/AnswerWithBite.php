<?php

namespace App\Jobs;

use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\BiteBot;
use App\Services\BiteSummons;
use App\Support\BiteCard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Answers one message that was addressed to Bite.
 *
 * Off the request path because it is a chain of provider round trips — searches,
 * a file read, then the answer — and the person who typed it is waiting on the
 * send endpoint, not on the reply. The reply arrives the way any colleague's
 * does: a broadcast, a moment later.
 *
 * Takes an id rather than the model, like {@see IndexMessage}: a message deleted
 * between dispatch and execution is a no-op, not a failure.
 *
 * # Why it decides again whether it was summoned
 *
 * {@see BiteSummons} already said yes at dispatch. It is asked again here
 * because everything it checked can have changed in the seconds since — the
 * feature switched off, the message edited to remove the mention, the sender
 * suspended — and because a retried job must not answer a message whose answer
 * already went out.
 */
class AnswerWithBite implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, not three.
     *
     * A failure here is almost always the provider refusing or timing out, and
     * retrying it means paying for the whole tool loop again to produce a second
     * copy of a reply the first attempt may already have posted. A missing
     * answer is recoverable by asking again; a duplicated one is not.
     */
    public int $tries = 1;

    /**
     * Generous, because the loop is up to eight provider round trips and one of
     * them may be a model reading a PDF. Still bounded: a hung request must not
     * hold a worker forever.
     */
    public int $timeout = 180;

    public function __construct(private readonly int $messageId) {}

    /**
     * Serialized per message, so a double-dispatch cannot produce two answers.
     *
     * `dontRelease` rather than a delay: the second copy has nothing to add, so
     * the right outcome is for it to be dropped rather than to run later.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bite:'.$this->messageId))->dontRelease()];
    }

    public function handle(BiteSummons $summons, BiteBot $bots, BiteAttachments $attachments): void
    {
        $message = Message::query()
            ->with(['sender', 'channel', 'conversation'])
            ->find($this->messageId);

        if ($message === null) {
            return;
        }

        $bot = $summons->summonedBy($message);

        if ($bot === null) {
            return;
        }

        $container = $message->container();
        $sender = $message->sender;

        if ($container === null || $sender === null) {
            return;
        }

        $context = new BiteContext(
            workspace: $message->workspace,
            user: $sender,
            container: $container,
            message: $message,
        );

        $agent = new Bite($context, $bot, $attachments);

        try {
            /*
             * `forUser` starts a stored agent conversation owned by the person
             * who asked, which is what makes a paused tool approval resumable
             * minutes later — the pending call has to still exist when they
             * press the button. It also scopes that stored history to them, so
             * two people asking Bite different things in the same channel do
             * not end up sharing one.
             */
            $response = $agent->forUser($sender)->prompt($this->promptFrom($message));
        } catch (Throwable $exception) {
            report($exception);

            $this->post(
                $bots,
                $container,
                $bot,
                $message,
                'I could not answer that just now — something went wrong reaching the model. Try again in a moment.',
            );

            return;
        }

        $this->reply($bots, $context, $bot, $response, $attachments);
    }

    /**
     * Post whatever came back, including a card if a tool is waiting to be
     * approved.
     */
    private function reply(
        BiteBot $bots,
        BiteContext $context,
        User $bot,
        AgentResponse $response,
        BiteAttachments $attachments,
    ): void {
        $message = $context->message;

        if ($message === null) {
            return;
        }

        $text = trim((string) $response);

        $card = $response->hasPendingApprovals()
            ? BiteCard::forApprovals($response->conversationId, $response->pendingApprovals)
            : [];

        /*
         * A response that paused for approval often has no prose at all — the
         * model called the tool and stopped. The card carries the detail, so
         * the body only has to be something the timeline can render and a
         * notification can preview.
         */
        if ($text === '' && $card !== []) {
            $text = 'I need your go-ahead before I create that.';
        }

        if ($text === '') {
            return;
        }

        $this->post($bots, $context->container, $bot, $message, $text, $card, $attachments);
    }

    /**
     * Send Bite's message into the same place it was asked.
     *
     * Threaded under the summoning message when that message is itself a reply,
     * so an exchange inside a thread stays inside it. A top-level mention is
     * answered at top level rather than as a thread on itself — a channel where
     * every answer is hidden behind "1 reply" is one where nobody reads the
     * answers.
     *
     * @param  array<string, mixed>  $card
     */
    private function post(
        BiteBot $bots,
        Channel|Conversation $container,
        User $bot,
        Message $summons,
        string $body,
        array $card = [],
        ?BiteAttachments $attachments = null,
    ): void {
        $bots->post(
            container: $container,
            bot: $bot,
            body: $body,
            metadata: $card,
            parent: $summons->parent_message_id !== null ? $summons : null,
            attachments: $attachments?->drain(),
        );
    }

    /**
     * What the model is actually asked.
     *
     * The body is passed through as written rather than stripped of the `@bite`
     * that summoned it: the mention is part of how the sentence reads, and a
     * model handed "can you find the deck?" with the addressee removed
     * occasionally answers as though it were overhearing rather than being
     * asked.
     */
    private function promptFrom(Message $message): string
    {
        return $message->body;
    }
}
