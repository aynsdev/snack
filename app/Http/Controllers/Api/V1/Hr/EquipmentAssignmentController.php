<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\HandOverEquipment;
use App\Actions\Hr\NotifyEquipment;
use App\Enums\EquipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AssignEquipmentRequest;
use App\Http\Requests\Hr\ReturnEquipmentRequest;
use App\Http\Resources\Hr\EquipmentAssignmentResource;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Equipment changing hands, for the mobile app: HR handing it out and taking
 * it back, and the holder asking to give it back.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\EquipmentAssignmentController};
 * every hand-over goes through HandOverEquipment. Each answers with the
 * assignment as it now stands.
 */
class EquipmentAssignmentController extends Controller
{
    /** What an assignment needs loaded to be rendered. */
    private const LOAD = ['equipment.category', 'equipment.workspace', 'employee.user', 'assigner', 'returner'];

    public function __construct(private readonly HandOverEquipment $handOver) {}

    public function store(Workspace $workspace, Equipment $equipment, AssignEquipmentRequest $request): JsonResponse
    {
        $employee = $workspace->employees()->with('user')->findOrFail($request->integer('employee_id'));

        $assignment = $this->handOver->assign(
            $equipment,
            $employee,
            $request->user(),
            $request->validated('condition'),
            $request->filled('assigned_on') ? Carbon::parse($request->validated('assigned_on')) : null,
        );

        return (new EquipmentAssignmentResource($assignment->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function takeBack(Workspace $workspace, EquipmentAssignment $equipmentAssignment, ReturnEquipmentRequest $request): EquipmentAssignmentResource
    {
        $this->handOver->takeBack(
            $equipmentAssignment,
            $request->user(),
            EquipmentStatus::from((string) $request->validated('status')),
            $request->validated('condition'),
            $request->filled('returned_on') ? Carbon::parse($request->validated('returned_on')) : null,
        );

        return new EquipmentAssignmentResource($equipmentAssignment->fresh(self::LOAD));
    }

    public function requestReturn(Workspace $workspace, EquipmentAssignment $equipmentAssignment, NotifyEquipment $notify): EquipmentAssignmentResource
    {
        $this->authorize('requestReturn', $equipmentAssignment);

        $equipmentAssignment->update(['return_requested_at' => now()]);

        $notify->returnRequested($equipmentAssignment);

        return new EquipmentAssignmentResource($equipmentAssignment->fresh(self::LOAD));
    }
}
