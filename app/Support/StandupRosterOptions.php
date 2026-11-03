<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Standup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * What the standup form needs to offer a roster: each channel it may point at,
 * with the ids of the people a standup there could ask, and those people.
 *
 * Shared by the web screen and the API so the two pickers cannot disagree
 * about who is eligible — the same exclusions {@see Standup::participants()}
 * makes at ask time.
 */
class StandupRosterOptions
{
    /**
     * @return array<int, array{id: int, name: string, slug: string, type: string, member_ids: array<int, int>}>
     */
    public static function channels(Workspace $workspace): array
    {
        $channels = $workspace->channels()
            ->notArchived()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type']);

        $members = Standup::liveMemberIdsByChannel($workspace->id, $channels->modelKeys());

        return $channels
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'type' => $channel->type->value,
                'member_ids' => $members[$channel->id] ?? [],
            ])
            ->all();
    }

    /**
     * Every active human in the workspace, for the picker to label ids with.
     *
     * @return array<int, array{id: int, name: string, username: string, initials: string, avatar_url: string|null}>
     */
    public static function people(Workspace $workspace): array
    {
        return User::query()
            ->where('is_bot', false)
            ->whereIn(
                'id',
                WorkspaceMember::query()
                    ->where('workspace_id', $workspace->id)
                    ->active()
                    ->select('user_id'),
            )
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'initials' => $user->initials(),
                'avatar_url' => $user->avatarUrl(),
            ])
            ->all();
    }
}
