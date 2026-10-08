<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\RequestStatus;
use App\Models\HrRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a pending shift, work-type, or attendance request,
 * makes an approval take effect, and writes it down.
 */
class ReviewHrRequest
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyHrRequest $notify,
    ) {}

    public function approve(HrRequest $request, User $reviewer, ?string $note = null): HrRequest
    {
        return $this->decide($request, $reviewer, RequestStatus::Approved, $note);
    }

    public function reject(HrRequest $request, User $reviewer, ?string $note = null): HrRequest
    {
        return $this->decide($request, $reviewer, RequestStatus::Rejected, $note);
    }

    private function decide(HrRequest $request, User $reviewer, RequestStatus $status, ?string $note): HrRequest
    {
        DB::transaction(function () use ($request, $reviewer, $status, $note): void {
            $request->update([
                'status' => $status,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if ($status === RequestStatus::Approved) {
                $request->applyApproval();
            }

            $this->audit->handle(
                workspace: $request->workspace,
                action: $request->auditAction($status === RequestStatus::Approved),
                actor: $reviewer,
                auditable: $request,
                newValues: $request->auditValues(),
            );
        });

        $this->notify->decided($request);

        return $request;
    }
}
