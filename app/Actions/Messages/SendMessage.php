<?php

namespace App\Actions\Messages;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageBody;
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
 */
class SendMessage
{
    public function handle(Channel|Conversation $container, User $sender, string $body): Message
    {
        $content = MessageBody::fromInput($body);

        $message = DB::transaction(function () use ($container, $sender, $content): Message {
            $message = Message::create([
                ...$content->toAttributes(),

                // Taken from the container, not from the request: a message must
                // never be able to name a workspace other than its own.
                'workspace_id' => $container->workspace_id,
                'channel_id' => $container instanceof Channel ? $container->id : null,
                'conversation_id' => $container instanceof Conversation ? $container->id : null,
                'sender_id' => $sender->id,
                'type' => $container instanceof Channel && $container->type->requiresElevatedPostingRights()
                    ? MessageType::Announcement
                    : MessageType::Text,
                'metadata' => [],
            ]);

            $container instanceof Channel
                ? $this->recordChannelActivity($container, $message)
                : $this->recordConversationActivity($container, $message);

            return $message;
        });

        /*
         * Both relations are attached before broadcasting so the queued worker
         * does not lazy-load them: the payload carries the author, and the
         * channel name is derived from the container's opaque public id.
         */
        $message->setRelation('sender', $sender);
        $message->setRelation($container instanceof Channel ? 'channel' : 'conversation', $container);

        MessageSent::dispatch($message);

        return $message;
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
