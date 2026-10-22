<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\EquipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\SaveEquipmentRequest;
use App\Http\Resources\Hr\EquipmentResource;
use App\Http\Resources\Hr\HrRequestResource;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR's equipment inventory for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\EquipmentController}.
 */
class EquipmentController extends Controller
{
    /** What an item needs loaded to be rendered with its holder and history. */
    private const LOAD = [
        'category',
        'openAssignment.employee.user', 'openAssignment.equipment.workspace', 'openAssignment.assigner',
        'assignments.employee.user', 'assignments.equipment.workspace', 'assignments.assigner', 'assignments.returner',
    ];

    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * Everything there is, with counts by category and status, the pending
     * equipment requests, and the people it could be handed to.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Equipment::class, $workspace]);

        $statusCounts = $workspace->equipment()
            ->toBase()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json(['data' => [
            'equipment' => EquipmentResource::collection(
                $workspace->equipment()->with(self::LOAD)->orderBy('name')->get(),
            )->resolve($request),
            'categories' => $workspace->equipmentCategories()
                ->withCount('equipment')
                ->orderBy('name')
                ->get()
                ->map(fn (EquipmentCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'count' => (int) $category->equipment_count,
                ])
                ->all(),
            'status_counts' => array_map(fn (EquipmentStatus $status): array => [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($statusCounts[$status->value] ?? 0),
            ], EquipmentStatus::cases()),
            'statuses' => EquipmentStatus::options(),
            'requests' => HrRequestResource::collection(
                $workspace->equipmentRequests()->pending()->with(['employee.user', 'category', 'reviewer', 'workspace'])->oldest()->get(),
            )->resolve($request),
            'employees' => $workspace->employees()
                ->employed()
                ->with('user')
                ->get()
                ->map(fn (Employee $employee): array => ['value' => (string) $employee->id, 'label' => $employee->user->name])
                ->sortBy('label')
                ->values()
                ->all(),
        ]]);
    }

    public function store(Workspace $workspace, SaveEquipmentRequest $request): JsonResponse
    {
        $equipment = $workspace->equipment()->create($request->validated());

        $this->audit->handle($workspace, AuditAction::EquipmentCreated, $request->user(), $equipment, newValues: ['name' => $equipment->name]);

        return (new EquipmentResource($equipment->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function update(Workspace $workspace, Equipment $equipment, SaveEquipmentRequest $request): EquipmentResource
    {
        $equipment->fill($request->validated());
        $changed = $equipment->getDirty();
        $equipment->save();

        if ($changed !== []) {
            $this->audit->handle($workspace, AuditAction::EquipmentUpdated, $request->user(), $equipment, newValues: [
                'name' => $equipment->name,
                'changed' => array_keys($changed),
            ]);
        }

        return new EquipmentResource($equipment->fresh(self::LOAD));
    }

    /**
     * Refused with a 422 while somebody has it: take it back first, or retire
     * it to keep its history instead.
     */
    public function destroy(Workspace $workspace, Equipment $equipment, Request $request): Response
    {
        $this->authorize('delete', $equipment);

        if ($equipment->status === EquipmentStatus::Assigned) {
            throw ValidationException::withMessages([
                'equipment' => "{$equipment->name} is handed out. Take it back before deleting it.",
            ]);
        }

        $equipment->delete();

        $this->audit->handle($workspace, AuditAction::EquipmentDeleted, $request->user(), $workspace, oldValues: ['name' => $equipment->name]);

        return response()->noContent();
    }
}
