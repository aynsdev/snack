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
        $canManage = $user?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'key' => $this->key,
            'description' => $this->description,
            'repository_url' => $this->repository_url,
            'repository_base_branch' => $this->repository_base_branch,
            'color' => $this->color,
            'uses_sprints' => $this->uses_sprints,
            'is_archived' => $this->is_archived,

            // The number the next card filed here will carry. Sent so the
            // settings screen can show where numbering has reached rather than
            // asking somebody to work it out from the last card they saw.
            'next_task_number' => $this->next_task_number,

            // The heading this project sits under, by id. The group's name is
            // sent once alongside the list rather than repeated on every
            // project — see ProjectGroupResource.
            'project_group_id' => $this->project_group_id,

            // Resolved rather than raw, so a card renders the same whether the
            // project has ever opened its settings — see Project::cardFields().
            'card_fields' => $this->cardFields(),

            /*
             * Where the project's agents talk and which columns they use. Only
             * for someone who can change them: which channel a project exports
             * to the agent dispatcher is the leads' business, not every reader's.
             */
            'agent_channel_id' => $this->when($canManage, fn (): ?int => $this->agent_channel_id),
            'agent_in_progress_column_id' => $this->when($canManage, fn (): ?int => $this->agent_in_progress_column_id),
            'agent_review_column_id' => $this->when($canManage, fn (): ?int => $this->agent_review_column_id),

            // Whether the task panel offers its agent controls. Says only that
            // agents are on, not where they talk.
            'agents_enabled' => $this->agent_channel_id !== null && $this->agentsEnabled(),

            'member_count' => $this->whenCounted('members'),
            'task_count' => $this->whenCounted('tasks'),
            'is_member' => $user !== null && $this->hasMember($user),

            // Whether this viewer keeps it at the top of their sidebar. Per
            // person, so it rides on their membership rather than on the
            // project — see the `starred_at` migration.
            'is_starred' => $user !== null && $this->isStarredBy($user),

            'abilities' => [
                'contribute' => $user?->can('contribute', $this->resource) ?? false,
                'manage' => $canManage,

                /*
                 * Whether this person can put themselves on the project, or
                 * take themselves off it. Both read the membership row already
                 * loaded for the viewer, so neither costs a query — and without
                 * them the client would have to infer "can join" from
                 * `is_member`, which is wrong for a guest and for an archived
                 * project.
                 */
                'join' => $user?->can('join', $this->resource) ?? false,
                'leave' => $user?->can('leave', $this->resource) ?? false,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
