<?php

namespace App\Services;

use App\Http\Resources\ChannelResource;
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
     * The full navigation payload for a workspace page.
     *
     * @return array<string, mixed>
     */
    public function payload(Workspace $workspace, User $user): array
    {
        return [
            'workspace' => (new WorkspaceResource($workspace))->resolve(request()),
            'channels' => $this->sidebarChannels($workspace, $user),

            // Direct and group conversations arrive in Phase 3; the key is
            // present now so the sidebar component's contract does not change.
            'conversations' => [],

            'unread' => [
                // Unread counts and mention badges are computed from the
                // last-read pointers in Phase 2, once messages exist.
                'channels' => (object) [],
                'mentions' => 0,
                'threads' => 0,
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
