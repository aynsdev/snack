<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;

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
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Project $project, User $actor, bool $archived): Project
    {
        if ($project->is_archived === $archived) {
            return $project;
        }

        $project->forceFill(['is_archived' => $archived])->save();

        $this->audit->handle(
            workspace: $project->workspace,
            action: $archived ? AuditAction::ProjectArchived : AuditAction::ProjectUnarchived,
            actor: $actor,
            auditable: $project,
            newValues: ['is_archived' => $archived],
        );

        return $project;
    }
}
