<?php

namespace App\Actions\Hr;

use App\Enums\RequestStatus;
use App\Models\HrRequest;
use App\Services\Hr\HrReviewers;
use App\Services\HrBot;

/**
 * Tells the right people about shift, work-type, and attendance requests, as
 * direct messages from the HR bot — the same way, and for the same reasons,
 * as {@see NotifyLeave}. Delivery failures are reported, never thrown.
 */
class NotifyHrRequest
{
    public function __construct(private readonly HrBot $bot) {}

    public function requested(HrRequest $request): void
    {
        $request->loadMissing(['employee.user', 'workspace']);

        $requester = $request->employee->user;

        $lines = ["**{$requester->name}** asked for a {$request->kind()}: {$request->summary()}."];

        if ($request->reason) {
            $lines[] = '> '.str_replace("\n", ' ', $request->reason);
        }

        $lines[] = 'Review it: '.$this->link($request);

        foreach (HrReviewers::for($request->workspace_id, $requester) as $reviewer) {
            rescue(fn () => $this->bot->directMessage($request->workspace, $reviewer, implode("\n", $lines)));
        }
    }

    public function decided(HrRequest $request): void
    {
        $request->loadMissing(['employee.user', 'workspace', 'reviewer']);

        $verdict = $request->status === RequestStatus::Approved ? 'approved ✅' : 'rejected';
        $by = $request->reviewer === null ? '' : " by {$request->reviewer->name}";

        $lines = ["Your {$request->kind()} ({$request->summary()}) was **{$verdict}**{$by}."];

        if ($request->review_note) {
            $lines[] = '> '.str_replace("\n", ' ', $request->review_note);
        }

        $lines[] = $this->link($request);

        rescue(fn () => $this->bot->directMessage($request->workspace, $request->employee->user, implode("\n", $lines)));
    }

    private function link(HrRequest $request): string
    {
        return route('hr.requests.index', ['workspace' => $request->workspace->slug]);
    }
}
