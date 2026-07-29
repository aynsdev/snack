<?php

namespace App\Http\Resources;

use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A membership row for the admin member directory.
 *
 * Unlike {@see UserResource} this does expose the email address, so it must
 * only be returned from routes gated by
 * `WorkspacePolicy::viewMembers` / `manageMember`.
 *
 * Mirrors the `WorkspaceMember` type in resources/js/types/snack.ts.
 *
 * @mixin WorkspaceMember
 */
class WorkspaceMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'joined_at' => $this->joined_at->toIso8601String(),
            'user' => new UserResource($this->whenLoaded('user')),
            'email' => $this->whenLoaded('user', fn (): string => $this->user->email),
        ];
    }
}
