<?php

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending invitation for the admin invitations table.
 *
 * The token is never serialized — not even its hash — unless {@see withUrl()}
 * is called, which is how the shareable link's own card gets a URL to copy. An
 * emailed invitation has no URL to give: its plaintext exists only in the
 * message that carried it.
 *
 * Mirrors the `WorkspaceInvitation` type in resources/js/types/snack.ts.
 *
 * @mixin WorkspaceInvitation
 */
class WorkspaceInvitationResource extends JsonResource
{
    /**
     * Whether to include the shareable URL.
     *
     * Off by default and opted into per use, rather than derived from the row
     * being a link: the URL *is* the credential, so which responses carry it is
     * a decision worth making explicitly at each call site instead of a
     * property of the data that every future serialization inherits.
     */
    private bool $withUrl = false;

    public function withUrl(bool $withUrl = true): static
    {
        $this->withUrl = $withUrl;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'is_link' => $this->isLink(),
            'max_uses' => $this->max_uses,
            'use_count' => $this->use_count,
            'url' => $this->when($this->withUrl, fn (): ?string => $this->shareableUrl()),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invited_by' => new UserResource($this->whenLoaded('inviter')),
        ];
    }
}
