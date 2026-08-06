<?php

namespace App\Actions\Standups;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\Standup;
use App\Models\User;
use App\Services\StandupBot;
use Illuminate\Support\Facades\DB;

/**
 * Changes a standup's questions, schedule, or channel.
 *
 * # Editing questions does not rewrite history
 *
 * Answers snapshot the question they were given, so a run recorded last week
 * keeps the wording it was asked with. That is deliberate: showing old answers
 * under new questions reads as true and is not, which is worse than showing
 * nothing. See the `standup_responses` migration.
 *
 * # Editing the schedule does not disturb today
 *
 * A run that has already gone out is not withdrawn, re-asked, or re-timed by an
 * edit. Its close window is measured from when it was actually asked — see
 * {@see App\Models\StandupRun::closesAt()} — so moving the standup to 14:00 at
 * eleven o'clock leaves this morning's alone and takes effect tomorrow, which is
 * what an admin means by it.
 */
class UpdateStandup
{
    public function __construct(
        private readonly StandupBot $bot,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     questions?: array<int, string>,
     *     days_of_week?: array<int, int>,
     *     ask_at?: string,
     *     timezone?: string,
     *     reminder_after_minutes?: int|null,
     *     closes_after_minutes?: int,
     *     is_active?: bool,
     * }  $attributes
     * @param  Channel|null  $channel  A resolved channel to move the standup to,
     *                                 or null to leave it where it is.
     */
    public function handle(
        Standup $standup,
        User $actor,
        array $attributes,
        ?Channel $channel = null,
    ): Standup {
        $before = [
            'name' => $standup->name,
            'channel' => $standup->channel->slug,
            'schedule' => $standup->scheduleSummary(),
            'questions' => $standup->questionList(),
            'is_active' => $standup->is_active,
        ];

        DB::transaction(function () use ($standup, $actor, $attributes, $channel, $before): void {
            $standup->fill(array_filter(
                [
                    'name' => $attributes['name'] ?? null,
                    'questions' => isset($attributes['questions'])
                        ? array_values($attributes['questions'])
                        : null,
                    'days_of_week' => isset($attributes['days_of_week'])
                        ? array_values($attributes['days_of_week'])
                        : null,
                    'ask_at' => $attributes['ask_at'] ?? null,
                    'timezone' => $attributes['timezone'] ?? null,
                    'closes_after_minutes' => $attributes['closes_after_minutes'] ?? null,
                ],
                fn (mixed $value): bool => $value !== null,
            ));

            /*
             * These two are set outside the filter above, because null and
             * false are meaningful values for them rather than "unchanged":
             * clearing the reminder is how a team says "stop chasing us", and
             * `is_active = false` is the pause button.
             */
            if (array_key_exists('reminder_after_minutes', $attributes)) {
                $standup->reminder_after_minutes = $attributes['reminder_after_minutes'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $standup->is_active = $attributes['is_active'];
            }

            if ($channel !== null) {
                $standup->channel_id = $channel->id;
                $standup->setRelation('channel', $channel);
            }

            $standup->save();

            $this->audit->handle(
                workspace: $standup->workspace,
                action: AuditAction::StandupUpdated,
                actor: $actor,
                auditable: $standup,
                oldValues: $before,
                newValues: [
                    'name' => $standup->name,
                    'channel' => $standup->channel->slug,
                    'schedule' => $standup->scheduleSummary(),
                    'questions' => $standup->questionList(),
                    'is_active' => $standup->is_active,
                ],
            );
        });

        // A standup that has been moved reports into a channel the bot may not
        // be in yet, and a summary from a non-member is the one thing that makes
        // the app look broken to the team reading it.
        if ($channel !== null) {
            $this->bot->joinChannel($channel, $this->bot->accountFor($standup->workspace));
        }

        return $standup;
    }
}
