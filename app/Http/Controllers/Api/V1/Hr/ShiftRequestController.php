<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreShiftRequestRequest;
use App\Http\Resources\Hr\HrRequestResource;
use App\Models\ShiftRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Asking for a different shift, and HR deciding it — for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\ShiftRequestController}; decisions go
 * through ReviewHrRequest. Each answers with the request as it now stands, in
 * the shape the requests list uses.
 */
class ShiftRequestController extends Controller
{
    /** What a request needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'reviewer', 'workspace', 'shift'];

    public function store(Workspace $workspace, StoreShiftRequestRequest $request, NotifyHrRequest $notify): JsonResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        $shiftRequest = $employee->shiftRequests()->create([
            ...$request->safe()->only(['shift_id', 'starts_on', 'ends_on', 'reason']),
            'workspace_id' => $workspace->id,
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($shiftRequest);

        return (new HrRequestResource($shiftRequest->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function cancel(Workspace $workspace, ShiftRequest $shiftRequest): HrRequestResource
    {
        $this->authorize('cancel', $shiftRequest);

        $shiftRequest->update(['status' => RequestStatus::Cancelled]);

        return new HrRequestResource($shiftRequest->fresh(self::LOAD));
    }

    public function approve(Workspace $workspace, ShiftRequest $shiftRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->approve($shiftRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($shiftRequest->fresh(self::LOAD));
    }

    public function reject(Workspace $workspace, ShiftRequest $shiftRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->reject($shiftRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($shiftRequest->fresh(self::LOAD));
    }
}
