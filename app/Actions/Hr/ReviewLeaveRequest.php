<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Approves or rejects a pending leave request, and writes it down.
 */
class ReviewLeaveRequest
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyLeave $notify,
    ) {}

    public function approve(LeaveRequest $leaveRequest, User $reviewer, ?string $note = null): LeaveRequest
    {
        return $this->decide($leaveRequest, $reviewer, LeaveStatus::Approved, $note);
    }

    public function reject(LeaveRequest $leaveRequest, User $reviewer, ?string $note = null): LeaveRequest
    {
        return $this->decide($leaveRequest, $reviewer, LeaveStatus::Rejected, $note);
    }

    private function decide(LeaveRequest $leaveRequest, User $reviewer, LeaveStatus $status, ?string $note): LeaveRequest
    {
        $leaveRequest->update([
            'status' => $status,
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->handle(
            workspace: $leaveRequest->workspace,
            action: $status === LeaveStatus::Approved ? AuditAction::LeaveApproved : AuditAction::LeaveRejected,
            actor: $reviewer,
            auditable: $leaveRequest,
            newValues: [
                'employee' => $leaveRequest->employee->user->name,
                'starts_on' => $leaveRequest->starts_on->toDateString(),
                'ends_on' => $leaveRequest->ends_on->toDateString(),
                'days' => (float) $leaveRequest->days,
            ],
        );

        $this->notify->decided($leaveRequest);

        return $leaveRequest;
    }
}
