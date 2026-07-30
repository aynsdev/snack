<?php

namespace App\Actions\Conversations;

use App\Actions\Messages\PostSystemMessage;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;

/**
 * Removes someone from a group conversation.
 *
 * # Why the row survives
 *
 * Leaving stamps `left_at` rather than deleting the participant row. Three
 * things depend on that:
 *
 *  - the person keeps read access to the history they were actually part of,
 *    which is what {@see ConversationPolicy::view()} grants to a
 *    participant who has left;
 *  - their old messages keep a coherent context;
 *  - rejoining restores their read position instead of presenting the whole
 *    conversation as unread.
 *
 * A direct message cannot be left — there would be nothing to return to — and
 * the policy refuses it before this runs.
 */
class LeaveConversation
{
    public function __construct(private readonly PostSystemMessage $systemMessage) {}

    public function handle(Conversation $conversation, User $user): void
    {
        $participant = $conversation->participantFor($user);

        if ($participant === null || $participant->hasLeft()) {
            return;
        }

        $participant->forceFill(['left_at' => now()])->save();

        $conversation->unsetRelation('participants')->unsetRelation('users');

        $this->systemMessage->handle(
            $conversation,
            sprintf('%s left the conversation.', $user->name),
        );

        ConversationUpdated::dispatch($conversation);
    }
}
