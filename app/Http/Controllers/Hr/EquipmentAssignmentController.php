<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\HandOverEquipment;
use App\Actions\Hr\NotifyEquipment;
use App\Enums\EquipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AssignEquipmentRequest;
use App\Http\Requests\Hr\ReturnEquipmentRequest;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Equipment changing hands: HR handing it out and taking it back, and the
 * holder asking to give it back.
 */
class EquipmentAssignmentController extends Controller
{
    public function __construct(private readonly HandOverEquipment $handOver) {}

    public function store(Workspace $workspace, Equipment $equipment, AssignEquipmentRequest $request): RedirectResponse
    {
        $employee = $workspace->employees()->with('user')->findOrFail($request->integer('employee_id'));

        $this->handOver->assign(
            $equipment,
            $employee,
            $request->user(),
            $request->validated('condition'),
            $request->filled('assigned_on') ? Carbon::parse($request->validated('assigned_on')) : null,
        );

        return back()->with('success', "{$equipment->name} handed to {$employee->user->name}.");
    }

    public function takeBack(Workspace $workspace, EquipmentAssignment $equipmentAssignment, ReturnEquipmentRequest $request): RedirectResponse
    {
        $this->handOver->takeBack(
            $equipmentAssignment,
            $request->user(),
            EquipmentStatus::from((string) $request->validated('status')),
            $request->validated('condition'),
            $request->filled('returned_on') ? Carbon::parse($request->validated('returned_on')) : null,
        );

        return back()->with('success', "{$equipmentAssignment->equipment->name} is back.");
    }

    public function requestReturn(Workspace $workspace, EquipmentAssignment $equipmentAssignment, NotifyEquipment $notify): RedirectResponse
    {
        $this->authorize('requestReturn', $equipmentAssignment);

        $equipmentAssignment->update(['return_requested_at' => now()]);

        $notify->returnRequested($equipmentAssignment);

        return back()->with('success', 'HR knows you want to return it.');
    }
}
