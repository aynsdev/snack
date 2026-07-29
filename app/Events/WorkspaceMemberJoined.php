<?php

namespace App\Events;

use App\Models\WorkspaceMember;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone accepted an invitation and joined the workspace.
 *
 * Drives the live member directory and the "N people joined" toast.
 */
class WorkspaceMemberJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly WorkspaceMember $member) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('workspace.'.$this->member->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'workspace.member_joined';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $user = $this->member->user;

        return [
            'member' => [
                'id' => $this->member->id,
                'role' => $this->member->role->value,
                'joined_at' => $this->member->joined_at->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => $user->avatarUrl(),
                    'title' => $user->title,
                ],
            ],
        ];
    }
}
