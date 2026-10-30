<?php

namespace App\Policies;

use App\Enums\ActorVia;
use App\Models\Message;
use App\Models\User;
use App\Services\ActorContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Message authorization.
 *
 * Access to a message is always derived from access to its container, never
 * asserted independently — a client-supplied message id must never be able to
 * reach across a channel or workspace boundary.
 */
class MessagePolicy
{
    public function view(User $user, Message $message): Response
    {
        return $this->containerResponse($user, $message, 'view');
    }

    /**
     * Posting a reply, either at the top level or inside a thread.
     */
    public function create(User $user, Message $message): Response
    {
        return $this->containerResponse($user, $message, 'post');
    }

    /**
     * Editing is author-only. Not even a workspace admin may rewrite someone
     * else's words; the strongest thing they can do is delete the message,
     * which is audit-logged.
     */
    public function update(User $user, Message $message): Response
    {
        /*
         * Visibility is settled before anything else, and deliberately so.
         * Answering "you can only edit your own messages" to someone who cannot
         * see the container at all confirms the message exists — which for a
         * private channel or a DM is precisely what the 404 elsewhere is
         * protecting. Only once the container admits them do the cheaper,
         * more specific refusals become safe to give.
         */
        $view = $this->containerResponse($user, $message, 'view');

        if ($view->denied()) {
            return $view;
        }

        if (! $message->type->isAuthoredByUser()) {
            return Response::deny('System messages cannot be edited.');
        }

        if (! $message->wasSentBy($user)) {
            return Response::deny('You can only edit your own messages.');
        }

        if ($message->isAgentPost() && ! $this->isPostingAgent($message)) {
            return Response::deny('Only the agent that posted this can edit it.');
        }

        $container = $this->containerResponse($user, $message, 'post');

        if ($container->denied()) {
            return $container;
        }

        $window = config('snack.messages.edit_window_minutes');

        if ($window !== null && $message->created_at?->addMinutes((int) $window)->isPast() === true) {
            return Response::deny('This message is too old to edit.');
        }

        return Response::allow();
    }

    /**
     * Authors may delete their own messages; workspace admins may remove any
     * message for moderation, which writes an audit log entry.
     */
    public function delete(User $user, Message $message): Response
    {
        if (! $message->type->isAuthoredByUser()) {
            return Response::deny('System messages cannot be deleted.');
        }

        $view = $this->containerResponse($user, $message, 'view');

        if ($view->denied()) {
            return $view;
        }

        if ($message->wasSentBy($user)) {
            return Response::allow();
        }

        return $user->roleIn($message->workspace)?->canAdministerWorkspace() === true
            ? Response::allow()
            : Response::deny('You can only delete your own messages.');
    }

    public function react(User $user, Message $message): Response
    {
        if (! $message->type->isAuthoredByUser()) {
            return Response::deny('System messages cannot be reacted to.');
        }

        return $this->containerResponse($user, $message, 'post');
    }

    /**
     * Pinning is shared state visible to the whole container.
     */
    public function pin(User $user, Message $message): Response
    {
        return $this->containerResponse($user, $message, 'pinMessages');
    }

    /**
     * Saving is private to the user, so read access is enough.
     */
    public function save(User $user, Message $message): Response
    {
        return $this->containerResponse($user, $message, 'view');
    }

    /**
     * Delegate to the channel or conversation policy for the message's
     * container. A message always has exactly one, enforced by a database
     * CHECK constraint.
     */
    private function containerResponse(User $user, Message $message, string $ability): Response
    {
        $container = $message->container();

        if ($container === null) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($user)->inspect($ability, $container);
    }

    /**
     * An agent's post is its status report, edited only by the connector that
     * made it — never by its owner in the composer.
     */
    private function isPostingAgent(Message $message): bool
    {
        $actor = app(ActorContext::class)->current();

        return $actor->via === ActorVia::Agent
            && $actor->userId === $message->sender_id
            && $actor->connectorId !== null
            && $actor->connectorId === ($message->metadata['agent']['connector_id'] ?? null);
    }
}
