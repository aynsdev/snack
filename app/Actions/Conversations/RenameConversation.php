<?php

namespace App\Actions\Conversations;

use App\Actions\Messages\PostSystemMessage;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;

/**
 * Names, renames, or clears the name of a group conversation.
 *
 * Direct messages are titled by who is in them, so they cannot be renamed —
 * {@see ConversationPolicy::rename()} refuses before this runs,
 * and the database refuses a named direct conversation regardless.
 *
 * Clearing the name (passing null) is allowed and returns the conversation to
 * being titled by its participants.
 */
class RenameConversation
{
    public function __construct(private readonly PostSystemMessage $systemMessage) {}

    public function handle(Conversation $conversation, User $actor, ?string $name): Conversation
    {
        $name = $name === null || trim($name) === '' ? null : trim($name);

        if ($name === $conversation->name) {
            return $conversation;
        }

        $conversation->forceFill(['name' => $name])->save();

        $this->systemMessage->handle(
            $conversation,
            $name === null
                ? sprintf('%s cleared the conversation name.', $actor->name)
                : sprintf('%s named the conversation "%s".', $actor->name, $name),
        );

        ConversationUpdated::dispatch($conversation);

        return $conversation;
    }
}
