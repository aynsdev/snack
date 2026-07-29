<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Direct and group message authorization.
 *
 * Conversations have no public variant: participation is the only way in, so
 * every denial for a non-participant is a 404 rather than a 403.
 */
class ConversationPolicy
{
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $user->belongsToWorkspace($workspace)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Someone who left a group conversation keeps read access to the history
     * they were part of, but loses the ability to post.
     */
    public function view(User $user, Conversation $conversation): Response
    {
        if (! $user->belongsToWorkspace($conversation->workspace)) {
            return Response::denyAsNotFound();
        }

        return $conversation->participantFor($user) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Guests cannot open new conversations; they can only reply within the
     * channels and threads they were added to.
     */
    public function create(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canStartConversations() === true
            ? Response::allow()
            : Response::deny('Guests cannot start new conversations.');
    }

    public function post(User $user, Conversation $conversation): Response
    {
        if (! $user->belongsToWorkspace($conversation->workspace)) {
            return Response::denyAsNotFound();
        }

        return $conversation->hasActiveParticipant($user)
            ? Response::allow()
            : Response::deny('You are no longer part of this conversation.');
    }

    /**
     * Only group conversations can gain participants; adding a third person to
     * a direct message would silently break its uniqueness guarantee, so the
     * UI promotes it to a new group conversation instead.
     */
    public function addParticipants(User $user, Conversation $conversation): Response
    {
        if (! $conversation->type->supportsParticipantManagement()) {
            return Response::deny('Start a group conversation to add more people.');
        }

        return $this->post($user, $conversation);
    }

    public function rename(User $user, Conversation $conversation): Response
    {
        if (! $conversation->type->supportsParticipantManagement()) {
            return Response::deny('Direct messages cannot be renamed.');
        }

        return $this->post($user, $conversation);
    }

    /**
     * A direct message cannot be left — there would be nothing to return to.
     */
    public function leave(User $user, Conversation $conversation): Response
    {
        if ($conversation->type->isDirect()) {
            return Response::deny('Direct messages cannot be left.');
        }

        return $this->post($user, $conversation);
    }

    public function pinMessages(User $user, Conversation $conversation): Response
    {
        return $this->post($user, $conversation);
    }
}
