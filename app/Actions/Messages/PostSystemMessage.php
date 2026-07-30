<?php

namespace App\Actions\Messages;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageBody;

/**
 * Records something Snack itself did, in the timeline where it happened.
 *
 * "Ada added Bo" belongs in the conversation rather than in a toast: it is the
 * answer to "why is this person reading my messages?", and it needs to still be
 * there tomorrow. Storing it as an ordinary message means it paginates, renders,
 * and broadcasts through the paths that already exist.
 *
 * The row has no sender, and {@see MessageType::isAuthoredByUser()} is what
 * stops anyone from editing, deleting, or reacting to it.
 */
class PostSystemMessage
{
    public function handle(Channel|Conversation $container, string $text): Message
    {
        $content = MessageBody::fromInput($text);

        $message = Message::create([
            ...$content->toAttributes(),
            'workspace_id' => $container->workspace_id,
            'channel_id' => $container instanceof Channel ? $container->id : null,
            'conversation_id' => $container instanceof Conversation ? $container->id : null,
            'sender_id' => null,
            'type' => MessageType::System,
            'metadata' => [],
        ]);

        /*
         * Deliberately does not stamp the container's `last_message_at`. Being
         * added to a group should not push it above a colleague who actually
         * said something, and it must not light up an unread badge — the unread
         * query ignores messages with no sender for the same reason.
         */
        $message->setRelation($container instanceof Channel ? 'channel' : 'conversation', $container);

        MessageSent::dispatch($message);

        return $message;
    }
}
