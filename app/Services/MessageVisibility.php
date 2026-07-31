<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ChannelPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Which messages may this person read?", as a query constraint.
 *
 * Every other read path in Snack starts from a container the route already
 * resolved and the policy already authorized. Search and the file browser are
 * the two that do not: they start from the whole workspace and have to narrow
 * *down* to what the viewer is entitled to. That makes this the one place where
 * getting visibility wrong leaks across channels rather than merely 404-ing, so
 * it is defined once and used by both.
 *
 * The channel half delegates to {@see Channel::scopeVisibleTo()}, which is the
 * same rule {@see ChannelPolicy::view()} enforces per-record:
 * guests see only their memberships, everyone else also sees public and
 * announcement channels they have not joined. Two definitions of that rule would
 * eventually disagree, and the disagreement would be a private channel showing
 * up in someone's search results.
 */
class MessageVisibility
{
    /**
     * Narrow a messages query to the containers this user may read.
     *
     * Generic over the builder's model rather than pinned to {@see Message},
     * because the two callers reach it differently: search builds the query
     * directly, while the file browser gets it from a `whereHas('message', …)`
     * whose closure is typed by the relation, not by this method. The constraint
     * is the same either way — it is written in `messages` columns and belongs on
     * a query over that table.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  A query over `messages`.
     * @return Builder<TModel>
     */
    public function apply(Builder $query, Workspace $workspace, User $user): Builder
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            // Not a member at all. Match nothing rather than everything — a
            // caller that forgot to check membership must not be handed the
            // workspace.
            return $query->whereRaw('1 = 0');
        }

        $channels = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->visibleTo($user, $role)
            ->select('id');

        $conversations = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->visibleTo($user)
            ->select('id');

        return $query
            ->where('workspace_id', $workspace->id)
            ->where(fn (Builder $visible) => $visible
                ->whereIn('channel_id', $channels)
                ->orWhereIn('conversation_id', $conversations),
            );
    }
}
