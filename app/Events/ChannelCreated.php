<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new public or announcement channel appeared in the workspace.
 *
 * Broadcast on the workspace channel so every member's sidebar and channel
 * browser update without a refresh. Private channels are never announced here —
 * their existence is only revealed to people who are added to them.
 */
class ChannelCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Channel $channel) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('workspace.'.$this->channel->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'channel.created';
    }

    /**
     * Only the fields the sidebar renders. Broadcast payloads reach every
     * workspace member, so they carry no more than the UI needs.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channel' => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'slug' => $this->channel->slug,
                'description' => $this->channel->description,
                'type' => $this->channel->type->value,
                'is_private' => $this->channel->is_private,
                'is_archived' => $this->channel->is_archived,
                'created_by' => $this->channel->created_by,
            ],
        ];
    }
}
