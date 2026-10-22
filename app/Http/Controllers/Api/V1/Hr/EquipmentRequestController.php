<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ApproveEquipmentRequestRequest;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreEquipmentRequestRequest;
use App\Http\Resources\Hr\EquipmentAssignmentResource;
use App\Http\Resources\Hr\HrRequestResource;
use App\Models\EquipmentCategory;
use App\Models\EquipmentRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everybody's own equipment — what they have, had, and have asked for — and
 * HR deciding those requests, for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\EquipmentRequestController}.
 * Decisions go through ReviewHrRequest, which also hands over the item HR
 * picked when approving. Each mutation answers with the request as it now
 * stands.
 */
class EquipmentRequestController extends Controller
{
    /** What a request needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'category', 'reviewer', 'workspace'];

    public function mine(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('view', $workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $load = ['equipment.category', 'equipment.workspace', 'employee.user', 'assigner', 'returner'];

        return response()->json(['data' => [
            'current' => $employee === null ? [] : EquipmentAssignmentResource::collection(
                $employee->equipmentAssignments()->open()->with($load)->latest('assigned_on')->get(),
            )->resolve($request),
            'past' => $employee === null ? [] : EquipmentAssignmentResource::collection(
                $employee->equipmentAssignments()->whereNotNull('returned_on')->with($load)->latest('returned_on')->limit(20)->get(),
            )->resolve($request),
            'requests' => $employee === null ? [] : HrRequestResource::collection(
                $employee->equipmentRequests()->with(self::LOAD)->latest()->limit(20)->get(),
            )->resolve($request),
            'categories' => $workspace->equipmentCategories()
                ->orderBy('name')
                ->get()
                ->map(fn (EquipmentCategory $category): array => ['value' => (string) $category->id, 'label' => $category->name])
                ->all(),
            'can_request' => $user->can('create', [EquipmentRequest::class, $workspace]),
        ]]);
    }

    public function store(Workspace $workspace, StoreEquipmentRequestRequest $request, NotifyHrRequest $notify): JsonResponse
    {
        $equipmentRequest = $request->user()->employeeIn($workspace)->equipmentRequests()->create([
            'workspace_id' => $workspace->id,
            'equipment_category_id' => $request->integer('equipment_category_id'),
            'reason' => $request->validated('reason'),
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($equipmentRequest);

        return (new HrRequestResource($equipmentRequest->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function cancel(Workspace $workspace, EquipmentRequest $equipmentRequest): HrRequestResource
    {
        $this->authorize('cancel', $equipmentRequest);

        $equipmentRequest->update(['status' => RequestStatus::Cancelled]);

        return new HrRequestResource($equipmentRequest->fresh(self::LOAD));
    }

    /**
     * Approves, and — given an `equipment_id` of the kind asked for that is
     * available — hands it over in the same step.
     */
    public function approve(Workspace $workspace, EquipmentRequest $equipmentRequest, ApproveEquipmentRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        if ($request->filled('equipment_id')) {
            $equipmentRequest->allocation = $workspace->equipment()->findOrFail($request->integer('equipment_id'));
        }

        $review->approve($equipmentRequest, $request->user(), $request->validated('note'));

        return new HrRequestResource($equipmentRequest->fresh(self::LOAD));
    }

    public function reject(Workspace $workspace, EquipmentRequest $equipmentRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): HrRequestResource
    {
        $review->reject($equipmentRequest, $request->user(), $request->validated('note'));

        return new HrRequestResource($equipmentRequest->fresh(self::LOAD));
    }
}
