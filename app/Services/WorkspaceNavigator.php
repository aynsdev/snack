<?php

namespace App\Services;

use App\Http\Resources\ChannelResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

/**
 * Builds the navigation payload shared with every workspace page: the
 * workspace rail and the sidebar's channel list.
 *
 * Centralized here because it runs on *every* request inside a workspace, so
 * its query count is a fixed cost the whole app pays. Each method below is one
 * query with its relations eager-loaded; nothing lazy-loads per row.
 */
class WorkspaceNavigator
{
    public function __construct(private readonly UnreadCounter $unread) {}

    /**
     * Every workspace the user belongs to, for the left-hand rail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rail(User $user): array
    {
        return $user->workspaces()
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'initials' => $workspace->initials(),
                'logo_url' => $workspace->logoUrl(),
            ])
            ->all();
    }

    /**
     * The channels shown in the sidebar: the ones the user has actually joined,
     * ordered by name.
     *
     * The membership row is eager-loaded and constrained to this user, so
     * ChannelResource can read the unread pointer and mute flag without another
     * query per channel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarChannels(Workspace $workspace, User $user): array
    {
        $channels = $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels)->resolve();
    }

    /**
     * Public and announcement channels the user has not joined yet, for the
     * "Browse channels" affordance. Guests get nothing — they have no directory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function joinableChannels(Workspace $workspace, User $user): array
    {
        if ($user->roleIn($workspace)?->isGuest() !== false) {
            return [];
        }

        $channels = $workspace->channels()
            ->browsable()
            ->whereDoesntHave('members', fn ($query) => $query->where('user_id', $user->id))
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels)->resolve();
    }

    /**
     * The user's conversations, most recently active first.
     *
     * Both relations are eager-loaded and each serves one purpose:
     * `activeUsers` is the roster the row renders, and `participants` is
     * constrained to this user so ConversationResource can read their own mute
     * flag and read pointer. Loading either lazily would be an N+1 across the
     * whole DM list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarConversations(Workspace $workspace, User $user): array
    {
        $conversations = $workspace->conversations()
            ->visibleTo($user)
            ->with([
                'activeUsers',
                'participants' => fn ($query) => $query->where('user_id', $user->id),
            ])
            /*
             * NULLS LAST is the point of the raw ordering: a conversation that
             * was just started has no `last_message_at`, and Postgres sorts
             * NULLs first on DESC — which would pin every empty thread above
             * every live one.
             */
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->limit((int) config('snack.conversations.sidebar_limit', 30))
            ->get();

        return ConversationResource::collection($conversations)->resolve(request());
    }

    /**
     * The full navigation payload for a workspace page.
     *
     * @return array<string, mixed>
     */
    public function payload(Workspace $workspace, User $user): array
    {
        $channelUnread = $this->unread->forChannels($workspace, $user);
        $conversationUnread = $this->unread->forConversations($workspace, $user);

        return [
            'workspace' => (new WorkspaceResource($workspace))->resolve(request()),
            'channels' => $this->sidebarChannels($workspace, $user),
            'conversations' => $this->sidebarConversations($workspace, $user),

            'unread' => [
                // Cast so an empty PHP array serializes as `{}` rather than
                // `[]`, keeping the client's Record<number, …> type honest.
                'channels' => (object) $channelUnread,
                'conversations' => (object) $conversationUnread,

                /*
                 * Real @-mentions only.
                 *
                 * This badge labels the "Mentions" link, and that page lists
                 * `mentions` rows and nothing else — so anything counted here
                 * that is not a mention row sends the reader to an empty list.
                 * Unread DMs used to be added in, on the reasoning that every
                 * message in a conversation is addressed to you; true, but it
                 * made the badge disagree with the page it points at, which is
                 * the one thing a badge may never do. DMs are already badged
                 * loudly on their own sidebar rows, where clicking through
                 * actually shows them.
                 */
                'mentions' => $this->unread->mentionCount($workspace, $user),

                // How many followed threads have unread replies — see
                // UnreadCounter::threadCount() for why it counts threads and
                // not replies.
                'threads' => $this->unread->threadCount($workspace, $user),

                // The notification centre's own badge.
                'notifications' => $this->unread->notificationCount($workspace, $user),
            ],
        ];
    }

    /**
     * Where to send someone who lands on a workspace without naming a channel:
     * their first joined channel, preferring the workspace's default.
     */
    public function landingChannel(Workspace $workspace, User $user): ?Channel
    {
        return $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }
}
