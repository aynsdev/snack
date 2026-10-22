<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreAttendanceRequestRequest;
use App\Http\Resources\Hr\HrRequestResource;
use App\Models\AttendanceRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Asking HR to correct or add a shift on the timesheet, and HR deciding it — for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\AttendanceRequestController}; decisions go
 * through ReviewHrRequest. Each answers with the request as it now stands, in
 * the shape the requests list uses.
 */
class AttendanceRequestController extends Controller
{
    /** What a request needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'reviewer', 'workspace'];

    public function store(Workspace $workspace, StoreAttendanceRequestRequest $request, NotifyHrRequest $notify): JsonResponse
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

        return (new HrRequestResource($attendanceRequest->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function cancel(Workspace $workspace, AttendanceRequest $attendanceRequest): HrRequestResource
    {
        $this->authorize('cancel', $attendanceRequest);

        $attendanceRequest->update(['status' => RequestStatus::Cancelled]);

        return new HrRequestResource($attendanceRequest->fresh(self::LOAD));
    }

    public function approve(Workspace $workspace, AttendanceRequest $attendanceRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->approve($attendanceRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($attendanceRequest->fresh(self::LOAD));
    }

    public function reject(Workspace $workspace, AttendanceRequest $attendanceRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->reject($attendanceRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($attendanceRequest->fresh(self::LOAD));
    }
}
