<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as seen by one workspace member.
 *
 * `abilities` is the same split {@see App\Policies\ProjectPolicy} makes —
 * reading a board and working it are different rights — computed server-side so
 * the client never reimplements it. The flags decide what to *render*; every
 * request is re-authorized regardless.
 *
 * Mirrors the `Project` type in resources/js/types/snack.ts.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'key' => $this->key,
            'description' => $this->description,
            'color' => $this->color,
            'uses_sprints' => $this->uses_sprints,
            'is_archived' => $this->is_archived,

            // Resolved rather than raw, so a card renders the same whether the
            // project has ever opened its settings — see Project::cardFields().
            'card_fields' => $this->cardFields(),

            'member_count' => $this->whenCounted('members'),
            'task_count' => $this->whenCounted('tasks'),
            'is_member' => $user !== null && $this->hasMember($user),

            'abilities' => [
                'contribute' => $user?->can('contribute', $this->resource) ?? false,
                'manage' => $user?->can('update', $this->resource) ?? false,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
