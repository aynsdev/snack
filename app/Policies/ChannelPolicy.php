<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Channel authorization.
 *
 * The visibility rule here is the authoritative one and mirrors
 * {@see Channel::scopeVisibleTo()}: private channels require membership,
 * guests require membership for *every* channel, and everyone else can at
 * least preview public and announcement channels.
 */
class ChannelPolicy
{
    /**
     * Browsing the channel directory. Guests have no directory — they only see
     * the channels they were added to.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role->isGuest()
            ? Response::deny('Guests cannot browse channels.')
            : Response::allow();
    }

    public function view(User $user, Channel $channel): Response
    {
        $role = $user->roleIn($channel->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($channel->hasMember($user)) {
            return Response::allow();
        }

        // A private channel must not even confirm its own existence to someone
        // who was never invited to it.
        return $channel->isPreviewableBy($role)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canCreateChannels() === true
            ? Response::allow()
            : Response::deny('You do not have permission to create channels.');
    }

    /**
     * Renaming, re-describing, and changing channel settings.
     */
    public function update(User $user, Channel $channel): Response
    {
        $role = $user->roleIn($channel->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($channel->is_archived) {
            return Response::deny('Archived channels cannot be edited.');
        }

        if ($role->canAdministerWorkspace()) {
            return Response::allow();
        }

        return $channel->membershipFor($user)?->isChannelAdmin() === true
            ? Response::allow()
            : Response::deny('Only channel admins can edit this channel.');
    }

    public function archive(User $user, Channel $channel): Response
    {
        if ($channel->is_default) {
            return Response::deny('Default channels cannot be archived.');
        }

        return $this->update($user, $channel);
    }

    /**
     * Joining under one's own steam. Private channels are invite-only, and
     * guests must always be added by someone else.
     */
    public function join(User $user, Channel $channel): Response
    {
        $role = $user->roleIn($channel->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($channel->hasMember($user)) {
            return Response::deny('You are already a member of this channel.');
        }

        if ($channel->is_archived) {
            return Response::deny('This channel is archived.');
        }

        if ($channel->is_private || $role->isGuest()) {
            return Response::denyAsNotFound();
        }

        return $channel->workspace->allowsSelfServeChannelJoins()
            ? Response::allow()
            : Response::deny('An admin must add you to channels in this workspace.');
    }

    public function leave(User $user, Channel $channel): Response
    {
        if (! $channel->hasMember($user)) {
            return Response::deny('You are not a member of this channel.');
        }

        return $channel->is_default
            ? Response::deny('You cannot leave a default channel.')
            : Response::allow();
    }

    /**
     * Adding or removing other people.
     */
    public function manageMembers(User $user, Channel $channel): Response
    {
        return $this->update($user, $channel);
    }

    /**
     * Posting a message.
     *
     * Membership is required, so "post" is strictly narrower than "view": a
     * member previewing a public channel they have not joined can read but not
     * write until they join.
     */
    public function post(User $user, Channel $channel): Response
    {
        /*
         * Visibility is settled first so the denial reason cannot say more than
         * the viewer is entitled to know. Answering "join this channel to post
         * in it" for a private channel would confirm that a channel by that
         * name exists — the exact thing `view` returns a 404 to avoid.
         */
        $visible = $this->view($user, $channel);

        if ($visible->denied()) {
            return $visible;
        }

        $role = $user->roleIn($channel->workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        if ($channel->is_archived) {
            return Response::deny('This channel is archived.');
        }

        if (! $channel->hasMember($user)) {
            return Response::deny('Join this channel to post in it.');
        }

        return $channel->allowsPostingBy($role)
            ? Response::allow()
            : Response::deny('Only admins can post in an announcement channel.');
    }

    /**
     * Pinning is shared state, so it needs posting rights rather than mere
     * read access.
     */
    public function pinMessages(User $user, Channel $channel): Response
    {
        return $this->post($user, $channel);
    }
}
