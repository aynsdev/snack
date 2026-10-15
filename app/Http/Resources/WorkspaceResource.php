<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use App\Services\BiteSummons;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace as seen by one of its members.
 *
 * `abilities` is computed server-side so the client never has to reimplement
 * the role hierarchy to decide whether to render an admin control. The server
 * still re-authorizes every request; these flags only drive the UI.
 *
 * Mirrors the `Workspace` type in resources/js/types/snack.ts.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $request->user()?->roleIn($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'initials' => $this->initials(),
            'logo_url' => $this->logoUrl(),
            'description' => $this->description,
            'member_limit' => $this->member_limit,
            'created_at' => $this->created_at?->toIso8601String(),
            'role' => $role?->value,
            'abilities' => [
                'administer' => $role?->canAdministerWorkspace() ?? false,
                'manage_members' => $role?->canManageMembers() ?? false,
                'create_channels' => $role?->canCreateChannels() ?? false,
                'create_projects' => $role?->canCreateProjects() ?? false,
                'start_conversations' => $role?->canStartConversations() ?? false,
                'mention_everyone' => $role?->canMentionEveryone() ?? false,
                'manage_people' => $role?->canManagePeople() ?? false,
                'view_audit_logs' => $role?->canViewAuditLogs() ?? false,
                'is_guest' => $role?->isGuest() ?? false,
                'use_bite' => app(BiteSummons::class)->availableTo($this->resource, $request->user()),
            ],
        ];
    }
}
