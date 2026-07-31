<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\Workspace;

/**
 * Changes a workspace's details and its configuration.
 *
 * # Two audit actions, one form
 *
 * The screen presents these together, but the log deliberately separates them.
 * Renaming a workspace and turning off invitations are different kinds of event:
 * the first is cosmetic and the second closes the door on everyone outside, and
 * an administrator reading the trail back should be able to filter for the
 * second without wading through the first.
 *
 * So a save records `workspace.updated`, `workspace.settings_updated`, both, or
 * — when nothing actually changed — neither.
 *
 * # The slug does not follow the name
 *
 * Unlike a channel's, a workspace slug is the root of every URL its members
 * have bookmarked, every invitation link already sent, and the tenant key held
 * in the session. Regenerating it on a rename would invalidate all three at
 * once, so the slug is set when the workspace is created and left alone.
 */
class UpdateWorkspace
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{name?: string, description?: string|null, member_limit?: int}  $attributes
     * @param  array<string, mixed>|null  $settings  The full settings document,
     *                                               or null to leave it untouched.
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        array $attributes = [],
        ?array $settings = null,
    ): Workspace {
        $this->applyDetails($workspace, $actor, $attributes);

        if ($settings !== null) {
            $this->applySettings($workspace, $actor, $settings);
        }

        return $workspace;
    }

    /**
     * @param  array{name?: string, description?: string|null, member_limit?: int}  $attributes
     */
    private function applyDetails(Workspace $workspace, User $actor, array $attributes): void
    {
        $before = $this->snapshot($workspace);

        // Assigned by name rather than in a loop: each column has its own type,
        // and a dynamic property write would let a string reach member_limit.
        if (array_key_exists('name', $attributes)) {
            $workspace->name = $attributes['name'];
        }

        if (array_key_exists('description', $attributes)) {
            $workspace->description = $attributes['description'];
        }

        if (array_key_exists('member_limit', $attributes)) {
            $workspace->member_limit = $attributes['member_limit'];
        }

        if (! $workspace->isDirty()) {
            return;
        }

        $workspace->save();

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::WorkspaceUpdated,
            actor: $actor,
            auditable: $workspace,
            oldValues: $before,
            newValues: $this->snapshot($workspace),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function applySettings(Workspace $workspace, User $actor, array $settings): void
    {
        $before = $workspace->settings ?? [];

        if ($before == $settings) {
            return;
        }

        $workspace->forceFill(['settings' => $settings])->save();

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::WorkspaceSettingsUpdated,
            actor: $actor,
            auditable: $workspace,
            // The whole document both sides. It is small, and a diff of keys
            // would not say what a policy was before somebody flipped it.
            oldValues: $before,
            newValues: $settings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Workspace $workspace): array
    {
        return [
            'name' => $workspace->name,
            'description' => $workspace->description,
            'member_limit' => $workspace->member_limit,
        ];
    }
}
