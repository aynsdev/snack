<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel as seen by one workspace member.
 *
 * `is_member` and the unread counters are viewer-specific, which is why the
 * membership row is expected to be eager-loaded by the caller; resolving it
 * here per channel would be an N+1 across the whole sidebar.
 *
 * Mirrors the `Channel` type in resources/js/types/snack.ts.
 *
 * @mixin Channel
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $membership = $user !== null ? $this->membershipFor($user) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'topic' => $this->topic,
            'type' => $this->type->value,
            'is_private' => $this->is_private,
            'is_archived' => $this->is_archived,
            'is_default' => $this->is_default,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'member_count' => $this->whenCounted('members'),
            'is_member' => $membership !== null,
            'is_muted' => $membership->is_muted ?? false,

            // Which section of *this* viewer's sidebar the channel sits in.
            // Null for a channel they have not filed, and for one they have not
            // joined — a non-member has no membership row to file it on.
            'channel_group_id' => $membership?->channel_group_id,

            'notification_level' => $membership?->notification_level->value,
            'last_read_message_id' => $membership?->last_read_message_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
