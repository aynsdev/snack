<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\ReviewLeaveRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewLeaveRequestRequest;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\Workspace;

/**
 * Approving and rejecting are separate endpoints rather than a status field:
 * a double-submitted "Approve" must not be able to turn into a rejection.
 */
class LeaveReviewController extends Controller
{
    public function approve(
        Workspace $workspace,
        LeaveRequest $leaveRequest,
        ReviewLeaveRequestRequest $request,
        ReviewLeaveRequest $review,
    ): LeaveRequestResource {
        return $this->decided($review->approve($leaveRequest, $request->user(), $request->input('note')));
    }

    public function reject(
        Workspace $workspace,
        LeaveRequest $leaveRequest,
        ReviewLeaveRequestRequest $request,
        ReviewLeaveRequest $review,
    ): LeaveRequestResource {
        return $this->decided($review->reject($leaveRequest, $request->user(), $request->input('note')));
    }

    private function decided(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return new LeaveRequestResource($leaveRequest->load(['employee.user', 'leaveType', 'reviewer', 'workspace']));
    }
}
