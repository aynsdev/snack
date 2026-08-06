<?php

namespace App\Policies;

use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Standup authorization.
 *
 * # Two rights, held by different people
 *
 * *Configuring* a standup is a workspace-admin right. It schedules recurring
 * direct messages to everybody in a channel, which is the one thing in Snack an
 * ordinary member can set up that arrives in other people's inboxes without
 * their asking — so it sits with the people who already decide what the
 * workspace does to its members, alongside settings and invitations.
 *
 * *Answering* one is nobody's right but the person who was asked. That is not a
 * role check at all: it is whether this response row is theirs. An admin has no
 * business filling in a colleague's standup, and the fact that they can
 * administer the schedule does not change whose morning it was.
 */
class StandupPolicy
{
    /**
     * Seeing the standups a workspace runs.
     *
     * The same rank as changing them, deliberately. This screen lists who is
     * being messaged, when, and with what questions — and a member curious about
     * where the bot's questions come from has the answer in front of them: the
     * standup posts into a channel they are in and DMs them every morning.
     */
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    public function update(User $user, Standup $standup): Response
    {
        return $this->administers($user, $standup->workspace);
    }

    public function delete(User $user, Standup $standup): Response
    {
        return $this->administers($user, $standup->workspace);
    }

    /**
     * Answering.
     *
     * Ownership of the row and nothing else — see the class note. The run must
     * also still be open: a standup that has been written up and posted cannot
     * take a late answer, because the message the channel read would then be
     * missing something it claims to summarise.
     */
    public function respond(User $user, StandupResponse $response): Response
    {
        if ($response->user_id !== $user->id) {
            /*
             * Not found rather than forbidden. "You may not answer this" would
             * confirm that a particular response id exists and belongs to
             * somebody, which is a fact about a colleague's morning that the
             * asker has no claim to.
             */
            return Response::denyAsNotFound();
        }

        if (! $response->run->isOpen()) {
            return Response::deny('This standup has already been posted.');
        }

        return Response::allow();
    }

    private function administers(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role->canAdministerWorkspace()
            ? Response::allow()
            : Response::deny('Only workspace admins can manage standups.');
    }
}
