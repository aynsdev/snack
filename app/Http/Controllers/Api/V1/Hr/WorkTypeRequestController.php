<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreWorkTypeRequestRequest;
use App\Http\Resources\Hr\HrRequestResource;
use App\Models\Workspace;
use App\Models\WorkTypeRequest;
use Illuminate\Http\JsonResponse;

/**
 * Asking to work remote, on-site, or hybrid, and HR deciding it — for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\WorkTypeRequestController}; decisions go
 * through ReviewHrRequest. Each answers with the request as it now stands, in
 * the shape the requests list uses.
 */
class WorkTypeRequestController extends Controller
{
    /** What a request needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'reviewer', 'workspace'];

    public function store(Workspace $workspace, StoreWorkTypeRequestRequest $request, NotifyHrRequest $notify): JsonResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        $workTypeRequest = $employee->workTypeRequests()->create([
            ...$request->safe()->only(['work_type', 'starts_on', 'ends_on', 'reason']),
            'workspace_id' => $workspace->id,
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($workTypeRequest);

        return (new HrRequestResource($workTypeRequest->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function cancel(Workspace $workspace, WorkTypeRequest $workTypeRequest): HrRequestResource
    {
        $this->authorize('cancel', $workTypeRequest);

        $workTypeRequest->update(['status' => RequestStatus::Cancelled]);

        return new HrRequestResource($workTypeRequest->fresh(self::LOAD));
    }

    public function approve(Workspace $workspace, WorkTypeRequest $workTypeRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->approve($workTypeRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($workTypeRequest->fresh(self::LOAD));
    }

    public function reject(Workspace $workspace, WorkTypeRequest $workTypeRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->reject($workTypeRequest, $request->user(), $request->input('note'));

        return new HrRequestResource($workTypeRequest->fresh(self::LOAD));
    }
}
