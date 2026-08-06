<?php

namespace App\Actions\Standups;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Standup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a standup, and every run and answer it collected.
 *
 * # Why this is not the same as switching it off
 *
 * Pausing (`is_active = false`) is the reversible one, and it is what an admin
 * almost always wants: the questions survive, the history survives, and turning
 * it back on resumes tomorrow. Deleting is for a standup that should never have
 * existed — the cascade takes the answers with it, and no summary already posted
 * to the channel is touched, because those are messages the team has read and
 * replied to.
 *
 * The bot stays where it is. It authored every one of those summaries, and
 * removing the account would leave the channel's history with an author-shaped
 * hole; it also serves whatever other standups the workspace still runs.
 */
class DeleteStandup
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Standup $standup, User $actor): void
    {
        DB::transaction(function () use ($standup, $actor): void {
            $this->audit->handle(
                workspace: $standup->workspace,
                action: AuditAction::StandupDeleted,
                actor: $actor,
                auditable: $standup,
                oldValues: [
                    'name' => $standup->name,
                    'channel' => $standup->channel->slug,
                    'schedule' => $standup->scheduleSummary(),
                    'questions' => $standup->questionList(),
                ],
            );

            $standup->delete();
        });
    }
}
