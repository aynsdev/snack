<?php

namespace App\Http\Resources\Hr;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'is_pinned' => $this->is_pinned,
            'notified_everyone' => $this->notified_everyone,
            'channel' => $this->channel === null ? null : [
                'name' => $this->channel->name,
                'slug' => $this->channel->slug,
            ],
            'is_posted' => $this->message_id !== null,
            'author' => $this->author === null ? null : [
                'name' => $this->author->name,
                'initials' => $this->author->initials(),
                'avatar_url' => $this->author->avatarUrl(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
