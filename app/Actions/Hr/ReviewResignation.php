<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChecklistKind;
use App\Enums\ResignationStatus;
use App\Models\ChecklistTemplate;
use App\Models\Resignation;
use App\Models\User;

/**
 * Accepts or rejects a resignation. Accepting one starts the person's
 * offboarding from the default template, counting down to their last day —
 * unless offboarding is already under way.
 */
class ReviewResignation
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyChecklist $notify,
        private readonly StartChecklist $start,
    ) {}

    public function accept(Resignation $resignation, User $reviewer, ?string $note = null): Resignation
    {
        $this->decide($resignation, $reviewer, ResignationStatus::Approved, $note);

        $employee = $resignation->employee;
        $workspace = $resignation->workspace;

        ChecklistTemplate::seedDefaultsFor($workspace);
        $template = ChecklistTemplate::defaultFor($workspace, ChecklistKind::Offboarding);

        if ($template !== null && ! $employee->checklists()->ofKind(ChecklistKind::Offboarding)->inProgress()->exists()) {
            $this->start->handle($employee, $template, $reviewer, today(), $resignation->last_day_on);
        }

        return $resignation;
    }

    public function reject(Resignation $resignation, User $reviewer, ?string $note = null): Resignation
    {
        return $this->decide($resignation, $reviewer, ResignationStatus::Rejected, $note);
    }

    private function decide(Resignation $resignation, User $reviewer, ResignationStatus $status, ?string $note): Resignation
    {
        $resignation->update([
            'status' => $status,
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->handle(
            $resignation->workspace,
            $status === ResignationStatus::Approved ? AuditAction::ResignationAccepted : AuditAction::ResignationRejected,
            $reviewer,
            $resignation->employee,
            newValues: [
                'employee' => $resignation->employee->user->name,
                'last_day_on' => $resignation->last_day_on->toDateString(),
            ],
        );

        $this->notify->resignationDecided($resignation);

        return $resignation;
    }
}
