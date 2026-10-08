<?php

namespace App\Http\Controllers\Hr;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everybody's own equipment page — what they have, what they had, and what
 * they have asked for — and HR deciding those requests.
 */
class EquipmentRequestController extends Controller
{
    public function mine(Workspace $workspace, Request $request): Response
    {
        $this->authorize('view', $workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $load = ['equipment.category', 'equipment.workspace', 'employee.user', 'assigner', 'returner'];

        return Inertia::render('hr/my-equipment', [
            'current' => $employee === null ? [] : EquipmentAssignmentResource::collection(
                $employee->equipmentAssignments()->open()->with($load)->latest('assigned_on')->get(),
            )->resolve($request),
            'past' => $employee === null ? [] : EquipmentAssignmentResource::collection(
                $employee->equipmentAssignments()->whereNotNull('returned_on')->with($load)->latest('returned_on')->limit(20)->get(),
            )->resolve($request),
            'requests' => $employee === null ? [] : HrRequestResource::collection(
                $employee->equipmentRequests()->with(['employee.user', 'category', 'reviewer', 'workspace'])->latest()->limit(20)->get(),
            )->resolve($request),
            'categories' => $workspace->equipmentCategories()
                ->orderBy('name')
                ->get()
                ->map(fn (EquipmentCategory $category): array => ['value' => (string) $category->id, 'label' => $category->name])
                ->all(),
            'can_request' => $user->can('create', [EquipmentRequest::class, $workspace]),
        ]);
    }

    public function store(Workspace $workspace, StoreEquipmentRequestRequest $request, NotifyHrRequest $notify): RedirectResponse
    {
        $equipmentRequest = $request->user()->employeeIn($workspace)->equipmentRequests()->create([
            'workspace_id' => $workspace->id,
            'equipment_category_id' => $request->integer('equipment_category_id'),
            'reason' => $request->validated('reason'),
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($equipmentRequest);

        return back()->with('success', 'Request sent to HR.');
    }

    public function cancel(Workspace $workspace, EquipmentRequest $equipmentRequest): RedirectResponse
    {
        $this->authorize('cancel', $equipmentRequest);

        $equipmentRequest->update(['status' => RequestStatus::Cancelled]);

        return back()->with('success', 'Request withdrawn.');
    }

    public function approve(Workspace $workspace, EquipmentRequest $equipmentRequest, ApproveEquipmentRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        if ($request->filled('equipment_id')) {
            $equipmentRequest->allocation = $workspace->equipment()->findOrFail($request->integer('equipment_id'));
        }

        $review->approve($equipmentRequest, $request->user(), $request->validated('note'));

        $name = $equipmentRequest->employee->user->name;

        return back()->with('success', $equipmentRequest->allocation
            ? "Approved, and {$equipmentRequest->allocation->name} handed to {$name}."
            : "Approved {$name}'s request. Hand something over from the list when it is ready.");
    }

    public function reject(Workspace $workspace, EquipmentRequest $equipmentRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->reject($equipmentRequest, $request->user(), $request->validated('note'));

        return back()->with('success', "Rejected {$equipmentRequest->employee->user->name}'s request.");
    }
}
