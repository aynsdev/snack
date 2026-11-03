<?php

namespace App\Actions\Standups;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\Standup;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StandupBot;
use Illuminate\Support\Facades\DB;

/**
 * Installs a standup in a channel.
 *
 * Creating one also brings the bot account into being and puts it in the
 * channel, which is the whole of "installing the app": from here the scheduler
 * finds the standup on its own.
 *
 * Authorization is the caller's responsibility, and the channel arrives as a
 * resolved model — the request cannot name one.
 */
class CreateStandup
{
    public function __construct(
        private readonly StandupBot $bot,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     questions: array<int, string>,
     *     days_of_week: array<int, int>,
     *     ask_at: string,
     *     timezone: string,
     *     reminder_after_minutes?: int|null,
     *     closes_after_minutes?: int,
     *     is_active?: bool,
     *     participant_ids?: array<int, int>|null,
     * }  $attributes
     */
    public function handle(
        Workspace $workspace,
        Channel $channel,
        User $creator,
        array $attributes,
    ): Standup {
        $standup = DB::transaction(function () use ($workspace, $channel, $creator, $attributes): Standup {
            $standup = Standup::create([
                'workspace_id' => $workspace->id,
                // From the channel, not the request: a standup must never be
                // able to name a workspace other than its channel's.
                'channel_id' => $channel->id,
                'created_by' => $creator->id,
                'name' => $attributes['name'],
                'questions' => array_values($attributes['questions']),
                'days_of_week' => array_values($attributes['days_of_week']),
                'ask_at' => $attributes['ask_at'],
                'timezone' => $attributes['timezone'],
                'reminder_after_minutes' => $attributes['reminder_after_minutes'] ?? null,
                'closes_after_minutes' => $attributes['closes_after_minutes']
                    ?? (int) config('snack.standups.closes_after_minutes', 240),
                'is_active' => $attributes['is_active'] ?? true,
                'participant_ids' => isset($attributes['participant_ids'])
                    ? array_values($attributes['participant_ids'])
                    : null,
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::StandupCreated,
                actor: $creator,
                auditable: $standup,
                newValues: [
                    'name' => $standup->name,
                    'channel' => $channel->slug,
                    'schedule' => $standup->scheduleSummary(),
                    'questions' => $standup->questionList(),
                    'participant_ids' => $standup->participant_ids,
                ],
            );

            return $standup;
        });

        /*
         * The bot joins outside the transaction.
         *
         * It writes to `users`, `workspace_members` and `channel_members`, none
         * of which belong to the standup being created — and a failure here is
         * recoverable on the next run (the ask resolves the account again),
         * whereas rolling the standup back over it would lose the admin's work
         * for a reason they cannot act on.
         */
        $this->bot->joinChannel($channel, $this->bot->accountFor($workspace));

        return $standup->setRelation('channel', $channel);
    }
}
