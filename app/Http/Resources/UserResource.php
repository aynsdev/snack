<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a person, as seen by other workspace members.
 *
 * Deliberately narrow: this is embedded in every message, mention, and member
 * list, so anything added here is broadcast widely. Email is *not* included —
 * it is only exposed on the admin member screens via
 * {@see WorkspaceMemberResource}.
 *
 * Mirrors the `SnackUser` type in resources/js/types/snack.ts.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'initials' => $this->initials(),
            'avatar_url' => $this->avatarUrl(),
            'title' => $this->title,
            'department' => $this->department,
            'presence_status' => $this->presence_status->value,
            'status_emoji' => $this->currentStatusEmoji(),
            'status_message' => $this->currentStatusMessage(),
        ];
    }
}
