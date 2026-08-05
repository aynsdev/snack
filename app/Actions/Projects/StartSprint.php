<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Events\BoardChanged;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Starts a sprint: the moment the board stops showing nothing and starts showing
 * this fortnight's commitment.
 *
 * # One at a time
 *
 * Refused if the project already has one running. The database says the same
 * thing with a partial unique index — see the sprints migration — and this is
 * the half that turns it into a sentence somebody can act on rather than a
 * constraint violation. Both are needed: the index is what holds when two people
 * click Start a moment apart.
 */
class StartSprint
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @throws ValidationException when another sprint is already running
     */
    public function handle(Sprint $sprint, User $actor): Sprint
    {
        if ($sprint->isCompleted()) {
            throw ValidationException::withMessages([
                'sprint' => 'This sprint has already been completed.',
            ]);
        }

        if ($sprint->isActive()) {
            return $sprint;
        }

        $running = $sprint->project->sprints()->active()->first();

        if ($running !== null) {
            throw ValidationException::withMessages([
                'sprint' => "“{$running->name}” is still running. Complete it before starting another.",
            ]);
        }

        $sprint->forceFill([
            'started_at' => now(),
            // Only filled in when the team never said: a plan with no start date
            // becomes "it started today", which is true and is what the burndown
            // needs. A date they did choose is left exactly as they chose it.
            'starts_at' => $sprint->starts_at ?? now(),
        ])->save();

        $this->audit->handle(
            workspace: $sprint->project->workspace,
            action: AuditAction::SprintStarted,
            actor: $actor,
            auditable: $sprint->project,
            newValues: ['sprint' => $sprint->name, 'sprint_id' => $sprint->id],
        );

        // The board it replaces is somebody else's open tab.
        BoardChanged::dispatch($sprint->project);

        return $sprint;
    }
}
