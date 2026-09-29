<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\ReviewLeaveRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

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
    ): RedirectResponse {
        $review->approve($leaveRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Approved {$leaveRequest->employee->user->name}'s leave.");
    }

    public function reject(
        Workspace $workspace,
        LeaveRequest $leaveRequest,
        ReviewLeaveRequestRequest $request,
        ReviewLeaveRequest $review,
    ): RedirectResponse {
        $review->reject($leaveRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Rejected {$leaveRequest->employee->user->name}'s leave.");
    }
}
