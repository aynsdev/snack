<?php

namespace App\Actions\Messages;

use App\Actions\Attachments\LinkAttachments;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Events\ThreadActivity;
use App\Jobs\IndexMessage;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\MessageBody;
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
    ) {}

    /**
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

        $message = DB::transaction(function () use ($container, $sender, $content, $root, $files): Message {
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
                'metadata' => [],
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

            return $message;
        });

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

        // The vector folds in the filenames, so it is queued after the claim.
        IndexMessage::dispatch($message->id);

        MessageSent::dispatch($message);

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
