<?php

namespace App\Actions\Messages;

use App\Actions\Attachments\LinkAttachments;
use App\Enums\MagnificModel;
use App\Enums\MessageType;
use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Events\ThreadActivity;
use App\Jobs\AnswerWithBite;
use App\Jobs\DeliverMessageNotifications;
use App\Jobs\IndexMessage;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ActorContext;
use App\Services\BiteSummons;
use App\Services\MessageBody;
use App\Services\Outbound\AgentChannelMessages;
use App\Services\Outbound\AgentSummons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a message to a channel or a conversation.
 *
 * Authorization is the caller's responsibility. The container and sender arrive
 * as resolved models, never as client-supplied identifiers, so there is no way
 * for a request to post into a channel or DM it was not authorized against.
 *
 * Both container types run through here rather than through parallel actions,
 * because everything a caller could get wrong is shared: deriving the workspace
 * from the container instead of the request, hydrating the sender before the
 * broadcast, and stamping activity atomically.
 *
 * # Replies
 *
 * Passing `$parent` makes the message a thread reply. The parent must already
 * have been resolved *through the container* — see StoreMessageRequest — so a
 * reply structurally cannot attach itself to a message in another channel.
 */
class SendMessage
{
    public function __construct(
        private readonly SubscribeToThread $subscribe,
        private readonly SyncMentions $mentions,
        private readonly LinkAttachments $attachments,
        private readonly ActorContext $actors,
        private readonly AgentChannelMessages $agentChannels,
        private readonly AgentSummons $summons,
    ) {}

    /**
     * @param  array{kind: string, task_id: int, task_reference: string}|null  $agentContext  Merged into an agent's own `agent` block only; see SendMessageTool.
     * @param  Collection<int, MessageAttachment>|null  $attachments  Uploads to
     *                                                                claim for this message, already resolved through the workspace and
     *                                                                the uploader — see ResolvesAttachments. Never client ids.
     */
    public function handle(
        Channel|Conversation $container,
        User $sender,
        string $body,
        ?Message $parent = null,
        ?Collection $attachments = null,
        ?MagnificModel $biteModel = null,
        ?array $agentContext = null,
    ): Message {
        $content = MessageBody::fromInput($body);

        /*
         * Threads are flat. Replying to a reply joins the thread it is already
         * in rather than starting a nested one — a tree of replies is a
         * structure nobody can follow in a chat window, and it would make
         * "the thread this belongs to" a recursive walk instead of one column.
         */
        $root = $parent?->threadRootId();

        $files = $attachments ?? new Collection;

        /*
         * Through the agent channel's lock, taken before anything is written
         * when this message may be exported — a reply, or an agent's own
         * post — see AgentChannelMessages.
         */
        $channelId = $container instanceof Channel ? $container->id : null;
        $metadata = $this->agentMetadata($sender);

        if ($agentContext !== null && isset($metadata['agent'])) {
            $metadata['agent'] += $agentContext;
        }

        if ($biteModel !== null) {
            $metadata['bite_model'] = $biteModel->value;
        }

        $reportable = $root !== null || isset($metadata['agent']);
        $summoning = $container instanceof Channel && $this->summons->mayBeSummons($sender, $content->source, $metadata);

        $message = $this->agentChannels->transaction($container->workspace_id, $channelId, $reportable, function () use ($container, $sender, $content, $root, $files, $metadata, $summoning): Message {
            $message = Message::create([
                ...$content->toAttributes(),

                // Taken from the container, not from the request: a message must
                // never be able to name a workspace other than its own.
                'workspace_id' => $container->workspace_id,
                'channel_id' => $container instanceof Channel ? $container->id : null,
                'conversation_id' => $container instanceof Conversation ? $container->id : null,
                'parent_message_id' => $root,
                'sender_id' => $sender->id,
                /*
                 * Attachments deliberately do not make this a `file` message.
                 * Carrying files is a property of a message, not a kind of
                 * message — `has_attachments` says so — and spending the type on
                 * it would cost the announcement distinction, which decides who
                 * is allowed to post at all.
                 */
                'type' => $container instanceof Channel && $container->type->requiresElevatedPostingRights()
                    ? MessageType::Announcement
                    : MessageType::Text,
                'metadata' => $metadata,
            ]);

            /*
             * Inside the transaction so a message and its files commit together.
             * Outside it, a failure between the two would leave a message whose
             * attachments are still unclaimed — and therefore due to be swept by
             * the orphan pruner, silently emptying a sent message hours later.
             */
            $this->attachments->handle($message, $files);

            /*
             * A reply is thread activity, not container activity.
             *
             * It deliberately leaves `last_message_at` and `message_count`
             * alone: the timeline excludes replies (Message::scopeRootLevel)
             * and so does the unread badge, so bumping the container would
             * order the sidebar by messages the sidebar cannot show and light
             * up a channel nobody said anything in.
             */
            if ($root !== null) {
                $this->recordThreadActivity($root, $message);
            } elseif ($container instanceof Channel) {
                $this->recordChannelActivity($container, $message);
            } else {
                $this->recordConversationActivity($container, $message);
            }

            if ($container instanceof Channel) {
                if ($summoning) {
                    $this->summons->record($container, $message, $sender);
                }

                $this->agentChannels->created($message->setRelation('sender', $sender));
            }

            return $message;
        }, threadRootId: $root, summoning: $summoning);

        /*
         * Mentions are materialized before the broadcast, so a recipient whose
         * client reacts to the arrival by re-reading its badge counts finds the
         * row already there rather than a count that is one behind.
         */
        $this->mentions->handle($message, $sender);

        if ($root !== null) {
            $this->subscribe->afterReply($root, $message, $sender);
        }

        /*
         * Both relations are attached before broadcasting so the queued worker
         * does not lazy-load them: the payload carries the author, and the
         * channel name is derived from the container's opaque public id.
         */
        $message->setRelation('sender', $sender);
        $message->setRelation($container instanceof Channel ? 'channel' : 'conversation', $container);

        // Re-read rather than reusing the models handed in: the claim above is
        // an UPDATE, so the in-memory copies still say `message_id` is null.
        $message->setRelation(
            'attachments',
            $files->isEmpty() ? new Collection : $message->attachments()->get(),
        );

        /*
         * The broadcast goes first, ahead of the two jobs below.
         *
         * It is a queued job like they are, and a worker takes the queue in
         * order — so dispatching it last meant every message waited for
         * full-text indexing *and* the whole notification fan-out before it
         * reached anybody's screen. Nothing about it depends on either of them:
         * the mentions it needs were materialized further up, and the payload
         * is built from relations already in memory.
         *
         * Ordering is not a substitute for giving broadcasts their own worker
         * when the background queue gets busy, but it costs nothing and it is
         * the difference between "instant" and "eventually" on a single worker,
         * which is what most deployments actually run.
         */
        MessageSent::dispatch($message->loadAgentTask());

        /*
         * And the signal that badges the channel for everyone *not* looking at
         * it. Conversations get this from SendConversationMessage; channels had
         * no equivalent at all, which is why a message in another channel
         * produced nothing until the reader happened to navigate.
         *
         * Replies are excluded for the same reason they leave `last_message_at`
         * alone above: the container's badge counts what the container's
         * timeline shows, and it does not show replies.
         */
        if ($root === null && $container instanceof Channel) {
            $this->announceChannelActivity($container, $sender);
        }

        // The vector folds in the filenames, so it is queued after the claim.
        IndexMessage::dispatch($message->id);

        /*
         * Queued after the mentions are materialized, because that is what the
         * fan-out reads to decide who was addressed by name — dispatching it
         * before would race the rows it depends on and silently notify nobody.
         */
        DeliverMessageNotifications::dispatch($message->id);

        /*
         * And Bite, if it was the one being spoken to.
         *
         * Decided here rather than inside the job so that the overwhelming
         * majority of messages — every one that does not name it — cost a
         * config read and a regex instead of a queued job that wakes a worker
         * to discover it has nothing to do. {@see BiteSummons} asks again on
         * the other side anyway, because the answer can change in between.
         */
        if (app(BiteSummons::class)->summonedBy($message) !== null) {
            AnswerWithBite::dispatch($message->id);
        }

        /*
         * The reply itself goes to the thread's own channel, which only people
         * with the thread open are listening to. Everyone looking at the
         * container needs the parent's "N replies" affordance to move too, and
         * that is a different audience — so it is a second, deliberately tiny
         * event rather than leaking the reply body onto the container channel.
         */
        if ($root !== null) {
            $rootMessage = Message::query()->find($root);

            if ($rootMessage !== null) {
                // Given the container it already belongs to, so the queued
                // broadcast does not re-load it to name its channel.
                $rootMessage->setRelation($container instanceof Channel ? 'channel' : 'conversation', $container);

                ThreadActivity::dispatch($rootMessage);
            }
        }

        return $message;
    }

    /**
     * The `agent` block, on a message an agent sent as its owner.
     *
     * Stamped from the authenticated token rather than from anything the caller
     * passes, and only when the sender *is* that token's owner — so neither a
     * person nor an integration can make a message look like an agent's, or
     * make an agent's look like a person's.
     *
     * @return array<string, mixed>
     */
    private function agentMetadata(User $sender): array
    {
        $actor = $this->actors->current();

        if (! $actor->isAgentFor($sender)) {
            return [];
        }

        return ['agent' => [
            'connector_id' => $actor->connectorId,
            'name' => $actor->connectorName,
        ]];
    }

    /**
     * Denormalized thread activity on the root, incremented atomically for the
     * same reason the channel tally is: two people replying at once must not
     * read the same count and write the same value back.
     *
     * `last_reply_id` is what the unread-threads badge compares each follower's
     * pointer against, so it is stamped in the same statement as the count —
     * a count that moved without the id would badge a thread as unread forever.
     */
    private function recordThreadActivity(int $rootId, Message $reply): void
    {
        Message::query()->whereKey($rootId)->update([
            'reply_count' => DB::raw('reply_count + 1'),
            'last_reply_at' => $reply->created_at,
            'last_reply_id' => $reply->id,
        ]);
    }

    /**
     * Denormalized channel activity, updated with an atomic increment rather
     * than a read-modify-write. Two people posting at the same instant would
     * otherwise both read the same count and write the same value back, losing
     * one of the messages from the tally.
     */
    private function recordChannelActivity(Channel $channel, Message $message): void
    {
        $channel->newQuery()->whereKey($channel->id)->update([
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => $message->created_at,
        ]);

        $channel->forceFill([
            'message_count' => $channel->message_count + 1,
            'last_message_at' => $message->created_at,
        ])->syncOriginalAttributes(['message_count', 'last_message_at']);
    }

    /**
     * Tell the channel's other members something arrived.
     *
     * The sender is excluded: their own timeline already received the broadcast
     * and their sidebar has nothing to badge. Members who have muted the channel
     * are *not* excluded here — the client decides what to do with the signal,
     * and the unread count still has to be right for a muted channel, which is
     * rendered quietly rather than not at all.
     */
    private function announceChannelActivity(Channel $channel, User $sender): void
    {
        $recipients = ChannelMember::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', '!=', $sender->id)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($recipients !== []) {
            ChannelActivity::dispatch($channel->id, $recipients);
        }
    }

    /**
     * A conversation keeps no message tally — nothing renders one — but it does
     * keep `last_message_at`, which is what orders the DM list. Without the
     * stamp a busy DM would sink to the bottom of the sidebar.
     */
    private function recordConversationActivity(Conversation $conversation, Message $message): void
    {
        $conversation->newQuery()->whereKey($conversation->id)->update([
            'last_message_at' => $message->created_at,
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->syncOriginalAttributes(['last_message_at']);
    }
}
