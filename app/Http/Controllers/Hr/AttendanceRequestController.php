<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreAttendanceRequestRequest;
use App\Models\AttendanceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Asking HR to correct or add a shift on the timesheet, and HR deciding it.
 */
class AttendanceRequestController extends Controller
{
    public function store(Workspace $workspace, StoreAttendanceRequestRequest $request, NotifyHrRequest $notify): RedirectResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        $attendanceRequest = $employee->attendanceRequests()->create([
            ...$request->times(),
            'time_entry_id' => $request->validated('time_entry_id'),
            'reason' => $request->validated('reason'),
            'workspace_id' => $workspace->id,
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($attendanceRequest);

        return back()->with('success', 'Attendance correction requested.');
    }

    public function cancel(Workspace $workspace, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('cancel', $attendanceRequest);

        $attendanceRequest->update(['status' => RequestStatus::Cancelled]);

        return back()->with('success', 'Request withdrawn.');
    }

    public function approve(Workspace $workspace, AttendanceRequest $attendanceRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->approve($attendanceRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Approved {$attendanceRequest->employee->user->name}'s {$attendanceRequest->kind()}.");
    }

    public function reject(Workspace $workspace, AttendanceRequest $attendanceRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->reject($attendanceRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Rejected {$attendanceRequest->employee->user->name}'s {$attendanceRequest->kind()}.");
    }
}
