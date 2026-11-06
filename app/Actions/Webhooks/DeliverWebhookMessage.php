<?php

namespace App\Actions\Webhooks;

use App\Actions\Messages\SendMessage;
use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookThread;
use App\Services\ActorContext;
use App\Services\WebhookBot;
use App\Support\Actor;
use App\Support\WebhookPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns one webhook delivery into one message.
 *
 * # Why it goes through SendMessage
 *
 * Because a webhook's message is an ordinary message. It paginates, broadcasts,
 * renders, searches, gets reacted to and replied to in a thread, and counts
 * toward the channel's unread state — and a reader should not be able to tell it
 * arrived over HTTP rather than from a keyboard. Every one of those behaviours
 * lives in the message pipeline, and a second path that reimplemented half of
 * them would be a second path that drifts.
 *
 * Authorization is the caller's business here as everywhere. What is different
 * is what "authorized" means: there is no user, so the controller's lookup of a
 * live hook *is* the check, and the channel it delivers into is this hook's own
 * column rather than anything the request named.
 *
 * # Why the card is written after the send
 *
 * The same reason {@see App\Services\StandupBot::post()} does it. SendMessage
 * owns the pipeline and takes no metadata — the one thing no caller may do is
 * inject arbitrary structure into a message row — so the card is written
 * afterwards, on a row this action has just created and therefore owns.
 *
 * Unlike the standup card, this one is written *before* anything reads the
 * message, and it still has to reach the timeline: a deploy card that only
 * appeared after a refresh would look broken to the person watching for it. So
 * the metadata is written inside the same transaction, and the send is what
 * broadcasts — see the note on the ordering below.
 *
 * # Threads
 *
 * A delivery may reply under an earlier one: `$replyTo` when the caller named a
 * message by `thread_id` (the controller has already checked the hook owns it),
 * or `$threadKey` when it named the thread by its own id — see the
 * webhook_threads migration.
 */
class DeliverWebhookMessage
{
    public function __construct(
        private readonly WebhookBot $bot,
        private readonly SendMessage $messages,
        private readonly ActorContext $actors,
    ) {}

    public function handle(
        IncomingWebhook $webhook,
        WebhookPayload $payload,
        ?Message $replyTo = null,
        ?string $threadKey = null,
    ): Message {
        /*
         * Outside the transaction, and before the send. Creating the account
         * seats it in the workspace and the channel, which the message pipeline
         * assumes has already happened — and doing it here means a hook whose
         * bot an admin removed from the channel by hand quietly repairs itself
         * on the next delivery rather than posting as a stranger.
         */
        $bot = $this->bot->accountFor($webhook);

        $message = $threadKey === null
            ? $this->post($webhook, $bot, $payload, $replyTo)
            : $this->postUnderKey($webhook, $bot, $payload, $threadKey);

        $this->recordDelivery($webhook);

        return $message;
    }

    /**
     * Post under the thread `$threadKey` names, starting it if this is the first
     * delivery to use the key.
     *
     * Under a lock, because "transaction started" and "transaction updated" are
     * routinely sent milliseconds apart: without it both would find no thread,
     * both would start one, and the update would land as a second top-level
     * message instead of a reply.
     */
    private function postUnderKey(IncomingWebhook $webhook, User $bot, WebhookPayload $payload, string $threadKey): Message
    {
        $lock = Cache::lock("webhook-thread:{$webhook->id}:".sha1($threadKey), 10);

        return $lock->block(10, function () use ($webhook, $bot, $payload, $threadKey): Message {
            $thread = WebhookThread::query()
                ->with('message')
                ->where('incoming_webhook_id', $webhook->id)
                ->where('key', $threadKey)
                ->first();

            /*
             * A root that has since been deleted starts a fresh thread rather
             * than failing: the caller is mid-way through narrating something
             * and has no way to know a person tidied the channel.
             */
            $root = $thread?->message;

            $message = $this->post($webhook, $bot, $payload, $root);

            if ($root === null) {
                WebhookThread::query()->updateOrCreate(
                    ['incoming_webhook_id' => $webhook->id, 'key' => $threadKey],
                    ['message_id' => $message->id],
                );
            }

            return $message;
        });
    }

    private function post(IncomingWebhook $webhook, User $bot, WebhookPayload $payload, ?Message $parent): Message
    {
        // A webhook request authenticates as nobody, so it says what it is.
        $message = $this->actors->using(Actor::webhook($bot), fn (): Message => $this->messages->handle(
            container: $webhook->channel,
            sender: $bot,
            body: $payload->text,
            parent: $parent,
        ));

        /*
         * Written on every delivery, including the plain `{"text": "..."}` case
         * that carries no card at all.
         *
         * Skipping it when there is nothing to draw is tempting and wrong. The
         * block is the *snapshot* of what posted this message — see
         * {@see App\Support\WebhookPayload::toMetadata()} — and the sender's own
         * name is not a substitute for it, because the bot account follows the
         * hook when an admin renames it. Write it only when it differs, and a
         * rename silently rewrites the attribution on every message that had
         * agreed with the old name at the time.
         *
         * `saveQuietly`, matching StandupBot: the broadcast has already gone out
         * carrying the body, which is what the timeline draws, and re-saving
         * loudly would deliver the same message a second time. The card fills in
         * on the next load. That seam is real, and it is a better trade than
         * teaching SendMessage to take metadata — which would put an
         * unauthenticated caller's structure into the one function every message
         * in Snack passes through.
         */
        $message->metadata = [
            ...$message->metadata ?? [],
            'webhook' => $payload->toMetadata($webhook),
        ];

        $message->saveQuietly();

        return $message;
    }

    /**
     * Stamp the hook with when it last fired.
     *
     * A raw increment rather than a read-modify-write, because two builds
     * finishing together would otherwise both read the same count and both
     * write it back — and this number's only job is to tell an admin whether the
     * thing is alive.
     *
     * Deliberately not `touch()`: `updated_at` records when a human changed the
     * hook, and a CI job firing every ten minutes would otherwise make that
     * column useless for the question it exists to answer.
     */
    private function recordDelivery(IncomingWebhook $webhook): void
    {
        DB::table('incoming_webhooks')
            ->where('id', $webhook->id)
            ->update([
                'last_delivered_at' => now(),
                'delivery_count' => DB::raw('delivery_count + 1'),
            ]);
    }
}
