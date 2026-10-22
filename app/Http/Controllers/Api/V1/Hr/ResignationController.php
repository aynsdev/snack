<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\NotifyChecklist;
use App\Actions\Hr\ReviewResignation;
use App\Enums\ResignationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewResignationRequest;
use App\Http\Requests\Hr\StoreResignationRequest;
use App\Http\Resources\Hr\ResignationResource;
use App\Models\Resignation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Giving notice, and HR accepting it — which starts offboarding — for the
 * mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\ResignationController};
 * decisions go through ReviewResignation. Each answers with the resignation
 * as it now stands.
 */
class ResignationController extends Controller
{
    /** What a resignation needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'reviewer', 'workspace'];

    public function store(Workspace $workspace, StoreResignationRequest $request, NotifyChecklist $notify): JsonResponse
    {
        $resignation = $request->user()->employeeIn($workspace)->resignations()->create([
            'workspace_id' => $workspace->id,
            'reason' => $request->validated('reason'),
            'last_day_on' => $request->validated('last_day_on'),
            'status' => ResignationStatus::Pending,
        ]);

        $notify->resigned($resignation);

        return (new ResignationResource($resignation->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function withdraw(Workspace $workspace, Resignation $resignation): ResignationResource
    {
        $this->authorize('withdraw', $resignation);

        $resignation->update(['status' => ResignationStatus::Withdrawn]);

        return new ResignationResource($resignation->fresh(self::LOAD));
    }

    /**
     * Accepting starts offboarding from the default template.
     */
    public function accept(Workspace $workspace, Resignation $resignation, ReviewResignationRequest $request, ReviewResignation $review): ResignationResource
    {
        $review->accept($resignation, $request->user(), $request->validated('note'));

        return new ResignationResource($resignation->fresh(self::LOAD));
    }

    public function reject(Workspace $workspace, Resignation $resignation, ReviewResignationRequest $request, ReviewResignation $review): ResignationResource
    {
        $review->reject($resignation, $request->user(), $request->validated('note'));

        return new ResignationResource($resignation->fresh(self::LOAD));
    }
}
