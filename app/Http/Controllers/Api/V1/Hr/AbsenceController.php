<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreAbsenceRequest;
use App\Http\Resources\Hr\AbsenceResource;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Days somebody was missing without approved leave, on their HR file.
 */
class AbsenceController extends Controller
{
    public function store(
        Workspace $workspace,
        Employee $employee,
        StoreAbsenceRequest $request,
        RecordAuditLog $audit,
    ): JsonResponse {
        $absence = $employee->absences()->create([
            ...$request->validated(),
            'workspace_id' => $workspace->id,
            'recorded_by' => $request->user()->id,
        ]);

        $audit->handle($workspace, AuditAction::AbsenceRecorded, $request->user(), $absence, newValues: [
            'employee' => $employee->user->name,
            'date' => $absence->date->toDateString(),
            'type' => $absence->type->value,
        ]);

        return (new AbsenceResource($absence->load('recorder')))->response()->setStatusCode(201);
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        Absence $absence,
        Request $request,
        RecordAuditLog $audit,
    ): Response {
        $this->authorize('update', $employee);

        $absence->delete();

        $audit->handle($workspace, AuditAction::AbsenceDeleted, $request->user(), $employee, oldValues: [
            'date' => $absence->date->toDateString(),
            'type' => $absence->type->value,
        ]);

        return response()->noContent();
    }
}
