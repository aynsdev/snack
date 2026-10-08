<?php

namespace App\Actions\Hr;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hr\HrReviewers;
use App\Services\HrBot;

/**
 * Tells the right people about leave, as direct messages from the HR bot.
 *
 * A direct message rather than a channel post: a leave request is between one
 * person and whoever approves it, and a DM already raises a notification —
 * inbox, desktop, and email for someone away — through the pipeline every
 * other message uses, with each person's own preferences applied.
 *
 * Delivery failures are reported, never thrown. Filing or deciding leave has
 * already succeeded by the time this runs, and a chat outage must not turn that
 * into an error screen for something that was in fact saved.
 */
class NotifyLeave
{
    public function __construct(private readonly HrBot $bot) {}

    /**
     * A request came in: tell everyone who can decide it, except the person
     * who asked — an admin filing their own leave does not need telling.
     */
    public function requested(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['employee.user', 'leaveType', 'workspace']);

        $requester = $leaveRequest->employee->user;

        $lines = [
            "**{$requester->name}** requested leave: {$leaveRequest->leaveType->name}, {$this->span($leaveRequest)}.",
        ];

        if ($leaveRequest->reason) {
            $lines[] = '> '.str_replace("\n", ' ', $leaveRequest->reason);
        }

        $lines[] = 'Review it: '.$this->link($leaveRequest);

        foreach (HrReviewers::for($leaveRequest->workspace_id, $requester) as $reviewer) {
            $this->send($leaveRequest, $reviewer, implode("\n", $lines));
        }
    }

    /**
     * A request was approved or rejected: tell the person who asked.
     */
    public function decided(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['employee.user', 'leaveType', 'workspace', 'reviewer']);

        $verdict = $leaveRequest->status === LeaveStatus::Approved ? 'approved ✅' : 'rejected';
        $by = $leaveRequest->reviewer === null ? '' : " by {$leaveRequest->reviewer->name}";

        $lines = [
            "Your {$leaveRequest->leaveType->name} leave for {$this->span($leaveRequest)} was **{$verdict}**{$by}.",
        ];

        if ($leaveRequest->review_note) {
            $lines[] = '> '.str_replace("\n", ' ', $leaveRequest->review_note);
        }

        $lines[] = $this->link($leaveRequest);

        $this->send($leaveRequest, $leaveRequest->employee->user, implode("\n", $lines));
    }

    private function send(LeaveRequest $leaveRequest, User $recipient, string $body): void
    {
        rescue(fn () => $this->bot->directMessage($leaveRequest->workspace, $recipient, $body));
    }

    /**
     * "3 days, Oct 2 – Oct 6, 2026" — the range collapses to one date for a
     * single day.
     */
    private function span(LeaveRequest $leaveRequest): string
    {
        $days = (float) $leaveRequest->days;
        $count = (fmod($days, 1.0) === 0.0 ? (int) $days : $days).' '.($days === 1.0 ? 'day' : 'days');

        $range = $leaveRequest->starts_on->isSameDay($leaveRequest->ends_on)
            ? $leaveRequest->starts_on->format('M j, Y')
            : $leaveRequest->starts_on->format('M j').' – '.$leaveRequest->ends_on->format('M j, Y');

        return "{$count}, {$range}";
    }

    private function link(LeaveRequest $leaveRequest): string
    {
        return route('hr.leave.index', ['workspace' => $leaveRequest->workspace->slug]);
    }
}
