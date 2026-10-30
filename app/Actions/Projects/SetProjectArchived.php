<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Services\Outbound\AgentSettingsEvents;
use App\Services\Outbound\OutboundEvents;

/**
 * Archives a project, or restores one.
 *
 * Idempotent in both directions, and deliberately not a toggle: a
 * double-submitted "Archive" must not quietly bring the board back. The same
 * shape as {@see App\Actions\Channels\SetChannelArchived}, for the same reason.
 *
 * Archiving hides the project from the sidebar and makes every board write
 * refuse — see {@see App\Policies\ProjectPolicy::contribute()} — and destroys
 * nothing. The tasks, their history, and their references all survive, which is
 * the entire difference between this and deleting.
 */
class SetProjectArchived
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly AgentSettingsEvents $agentSettings,
        private readonly OutboundEvents $events,
    ) {}

    public function handle(Project $project, User $actor, bool $archived): Project
    {
        if ($project->is_archived === $archived) {
            return $project;
        }

        $this->events->transaction([$project->workspace_id], function () use ($project, $actor, $archived): void {
            $locked = Project::query()->lock('for no key update')->findOrFail($project->id);

            if ($locked->is_archived === $archived) {
                return;
            }

            // Archiving switches a project's agents off, and restoring it
            // switches them back on — so the dispatcher hears about both.
            if ($locked->agent_channel_id !== null) {
                $this->agentSettings->changing($locked);
            }

            $locked->forceFill(['is_archived' => $archived])->save();

            $this->audit->handle(
                workspace: $project->workspace,
                action: $archived ? AuditAction::ProjectArchived : AuditAction::ProjectUnarchived,
                actor: $actor,
                auditable: $locked,
                newValues: ['is_archived' => $archived],
            );
        });

        return $project->refresh();
    }
}
