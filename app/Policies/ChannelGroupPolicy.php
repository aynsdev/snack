<?php

namespace App\Policies;

use App\Models\ChannelGroup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Who arranges a channel list: the person whose list it is, and nobody else.
 *
 * This is the whole policy. {@see ProjectGroupPolicy} has to weigh workspace
 * roles because a shelf of projects is shared — one person's rearrangement is
 * everybody's. A channel group is private: it exists in one sidebar, is read on
 * one person's requests, and rearranging it changes nothing anyone else can see.
 * So the only questions are whether the viewer belongs to the workspace at all
 * and whether the group is theirs.
 *
 * Guests included, deliberately. They see fewer channels than anyone, but the
 * few they have are still a list, and there is nothing to protect: the group
 * holds no channels of its own and grants access to none.
 *
 * Another member's group is denied as not-found rather than forbidden. "You may
 * not touch this" would confirm that a particular person has a heading with a
 * particular id, which is a fact about their sidebar that is not ours to hand
 * out.
 */
class ChannelGroupPolicy
{
    /**
     * Reading your own headings — anyone who is in the workspace.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace) === null
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->viewAny($user, $workspace);
    }

    public function view(User $user, ChannelGroup $group): Response
    {
        return $group->user_id === $user->id && $user->roleIn($group->workspace) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Renaming a group, and moving it up or down the list.
     */
    public function update(User $user, ChannelGroup $group): Response
    {
        return $this->view($user, $group);
    }

    /**
     * Deleting a group, which destroys nothing: the channels filed under it
     * become ungrouped and are still in the sidebar — see the `nullOnDelete` in
     * the channel_groups migration.
     */
    public function delete(User $user, ChannelGroup $group): Response
    {
        return $this->view($user, $group);
    }
}
