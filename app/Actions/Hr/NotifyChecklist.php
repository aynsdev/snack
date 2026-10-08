<?php

namespace App\Actions\Hr;

use App\Enums\ResignationStatus;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistTask;
use App\Models\Resignation;
use App\Models\User;
use App\Services\Hr\HrReviewers;
use App\Services\HrBot;

/**
 * Tells people about onboarding, offboarding, and resignations, as direct
 * messages from the HR bot — see {@see NotifyLeave} for why. Delivery failures
 * are reported, never thrown.
 */
class NotifyChecklist
{
    public function __construct(private readonly HrBot $bot) {}

    /**
     * A checklist started: tell each person given steps what they are, except
     * whoever started it.
     */
    public function assigned(EmployeeChecklist $checklist, ?User $starter): void
    {
        $checklist->loadMissing(['tasks.assignee', 'employee.user', 'workspace']);

        $subject = $checklist->employee->user;
        $kind = strtolower($checklist->kind->label());

        $checklist->tasks
            ->filter(fn (EmployeeChecklistTask $task): bool => $task->assignee !== null && $task->assignee_id !== $starter?->id)
            ->groupBy('assignee_id')
            ->each(function ($tasks) use ($checklist, $subject, $kind): void {
                $assignee = $tasks->first()->assignee;
                $whose = $assignee->id === $subject->id ? 'your' : "**{$subject->name}**'s";

                $lines = ["You have steps in {$whose} {$kind}:"];

                foreach ($tasks as $task) {
                    $lines[] = "- {$task->title}".($task->due_on ? ' (by '.$task->due_on->format('M j').')' : '');
                }

                $lines[] = route('hr.index', ['workspace' => $checklist->workspace->slug]);

                rescue(fn () => $this->bot->directMessage($checklist->workspace, $assignee, implode("\n", $lines)));
            });
    }

    /**
     * Somebody resigned: tell HR.
     */
    public function resigned(Resignation $resignation): void
    {
        $resignation->loadMissing(['employee.user', 'workspace']);

        $person = $resignation->employee->user;
        $body = "**{$person->name}** resigned. Last day: {$resignation->last_day_on->format('M j, Y')}.\n"
            .'> '.str_replace("\n", ' ', $resignation->reason)."\n"
            .'Review it: '.route('hr.offboarding.index', ['workspace' => $resignation->workspace->slug]);

        foreach (HrReviewers::for($resignation->workspace_id, $person) as $reviewer) {
            rescue(fn () => $this->bot->directMessage($resignation->workspace, $reviewer, $body));
        }
    }

    /**
     * HR decided: tell the person who resigned.
     */
    public function resignationDecided(Resignation $resignation): void
    {
        $resignation->loadMissing(['employee.user', 'workspace', 'reviewer']);

        $lines = [$resignation->status === ResignationStatus::Approved
            ? "Your resignation was accepted. Your last day is {$resignation->last_day_on->format('M j, Y')}."
            : 'Your resignation was not accepted. HR will be in touch.'];

        if ($resignation->review_note) {
            $lines[] = '> '.str_replace("\n", ' ', $resignation->review_note);
        }

        rescue(fn () => $this->bot->directMessage($resignation->workspace, $resignation->employee->user, implode("\n", $lines)));
    }
}
